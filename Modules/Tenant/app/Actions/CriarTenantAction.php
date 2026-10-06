<?php

namespace Modules\Tenant\Actions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\ColectorDeCredenciais;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\DTO\CriarTenantDTO;
use Modules\Tenant\DTO\TenantCriado;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\OrdemDeProvisionamentoDuplicada;
use Modules\Tenant\Exceptions\ProvisionamentoIncompleto;
use Modules\Tenant\Models\Tenant;
use Modules\Tenant\Services\ClassificadorDominio;
use Modules\Tenant\Services\GeradorCodigoTenant;
use Modules\Tenant\Services\ValidadorDominio;

/**
 * Cria uma escola de ponta a ponta numa única transacção (spec §16.4): Tenant Activo,
 * domínio principal e os provisionadores de cada módulo, por ordem, dentro do contexto
 * do tenant novo. Se algo falhar, nada fica criado.
 *
 * Não conhece nenhum módulo de negócio: descobre os provisionadores pela etiqueta.
 */
class CriarTenantAction
{
    public function __construct(
        private readonly Container $container,
        private readonly TenantContext $contexto,
        private readonly ColectorDeCredenciais $credenciais,
        private readonly GeradorCodigoTenant $codigos,
        private readonly ValidadorDominio $dominios,
        private readonly ClassificadorDominio $classificador,
    ) {}

    /**
     * @throws DadosDeTenantInvalidos
     * @throws OrdemDeProvisionamentoDuplicada
     */
    public function executar(CriarTenantDTO $dto): TenantCriado
    {
        [$dados, $dominio, $codigo] = $this->validar($dto);
        $provisionadores = $this->provisionadoresOrdenados();
        $this->exigirProvisionadoresEsperados($provisionadores);

        $this->credenciais->esquecer();

        try {
            [$tenant, $dominioPrincipal, $credencial] = DB::transaction(function () use ($codigo, $dados, $dominio, $provisionadores) {
                $tenant = Tenant::create([
                    'codigo' => $codigo,
                    'nome' => $dados->nomeEstabelecimento,
                ]);

                $dominioPrincipal = $tenant->dominios()->create([
                    'dominio' => $dominio,
                    'tipo' => $this->classificador->classificar($dominio),
                    'is_principal' => true,
                ]);

                $this->contexto->executarComo($tenant->paraTenantAtual(), function ($atual) use ($provisionadores, $dados) {
                    foreach ($provisionadores as $provisionador) {
                        $provisionador->provisionar($atual, $dados);
                    }
                });

                $credencial = $this->credenciais->retirar();

                if ($credencial === null) {
                    throw new ProvisionamentoIncompleto('O provisioning terminou sem criar o administrador inicial (módulo de Autenticação desactivado ou provisionador em falta). Nada foi criado.');
                }

                return [$tenant, $dominioPrincipal, $credencial];
            });

            return new TenantCriado($tenant, $dominioPrincipal, $credencial);
        } catch (UniqueConstraintViolationException) {
            throw new DadosDeTenantInvalidos(['conflito' => 'O código ou o domínio foi registado por outro pedido em simultâneo; tente novamente. Nada foi criado.']);
        } finally {
            // Em caso de falha, a credencial gerada não pode ficar na memória.
            $this->credenciais->esquecer();
        }
    }

    /**
     * @param  list<ProvisionaTenant>  $provisionadores
     */
    private function exigirProvisionadoresEsperados(array $provisionadores): void
    {
        if ($provisionadores === []) {
            throw new ProvisionamentoIncompleto('Não há nenhum provisionador registado: nenhum módulo etiquetou o seu passo de provisioning. Nada foi criado.');
        }

        $presentes = array_map('get_class', $provisionadores);
        $emFalta = array_diff(config('tenancy.provisionadores_esperados', []), $presentes);

        if ($emFalta !== []) {
            throw new ProvisionamentoIncompleto('Provisionadores em falta (módulo desactivado?): ' . implode(', ', $emFalta) . '. Nada foi criado.');
        }
    }

    /**
     * @return array{0: DadosProvisionamento, 1: string, 2: string}
     */
    private function validar(CriarTenantDTO $dto): array
    {
        $erros = [];

        $nome = trim($dto->nomeEstabelecimento);
        $adminNome = trim($dto->nomeAdministrador);
        $adminEmail = mb_strtolower(trim($dto->emailAdministrador));

        if ($nome === '') {
            $erros['nome'] = 'O nome do estabelecimento é obrigatório.';
        }

        if ($adminNome === '') {
            $erros['admin_nome'] = 'O nome do administrador é obrigatório.';
        }

        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $erros['admin_email'] = 'O email do administrador é inválido.';
        }

        if ($dto->codigo !== null) {
            if (! $this->codigos->formatoValido($dto->codigo)) {
                $erros['codigo'] = "Código inválido: '{$dto->codigo}'. Formato esperado: MOSI-000000.";
            } elseif ($this->codigos->existe($dto->codigo)) {
                $erros['codigo'] = "O código '{$dto->codigo}' já existe.";
            }
        }

        if (config('tenancy.modo') === 'unico' && Tenant::query()->exists()) {
            $erros['modo'] = 'TENANCY_MODO=unico só admite um tenant por instalação, e já existe um.';
        }

        $dominio = '';
        try {
            $dominio = $this->dominios->validar($dto->dominioPrincipal);
        } catch (DadosDeTenantInvalidos $e) {
            $erros += $e->erros;
        }

        if ($erros !== []) {
            throw new DadosDeTenantInvalidos($erros);
        }

        return [new DadosProvisionamento($nome, $adminNome, $adminEmail), $dominio, $dto->codigo ?? $this->codigos->proximo()];
    }

    /**
     * @return list<ProvisionaTenant>
     */
    private function provisionadoresOrdenados(): array
    {
        $provisionadores = iterator_to_array($this->container->tagged(ProvisionaTenant::ETIQUETA), false);

        $porOrdem = [];
        foreach ($provisionadores as $provisionador) {
            $porOrdem[$provisionador->ordem()][] = $provisionador::class;
        }

        foreach ($porOrdem as $ordem => $classes) {
            if (count($classes) > 1) {
                throw new OrdemDeProvisionamentoDuplicada($ordem, $classes);
            }
        }

        usort($provisionadores, fn (ProvisionaTenant $a, ProvisionaTenant $b) => $a->ordem() <=> $b->ordem());

        return $provisionadores;
    }
}
