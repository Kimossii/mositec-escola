<?php

namespace Modules\Licenciamento\Actions;

use Carbon\CarbonImmutable;
use Modules\Core\Traits\LogService;
use Modules\Licenciamento\DTOs\EmitirLicencaDTO;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Exceptions\EmissorIndisponivel;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\EmissorDeLicencas;
use Modules\Licenciamento\Services\EscolasSemLicenca;
use Throwable;

/**
 * Transição para o licenciamento: emite uma licença ONLINE a cada escola desta instalação que ainda não tem licença válida
 * (as que já têm ficam intactas), com o plano e a validade indicados, sem autor e com o motivo «transição».
 * `simular` só lista. Nunca emite sem chave de emissão operacional.
 */
class EmitirLicencasDeTransicaoAction
{
    use LogService;

    public const MOTIVO = 'transição';

    public function __construct(
        private readonly EscolasSemLicenca $escolas,
        private readonly EmitirLicencaAction $emitir,
        private readonly EmissorDeLicencas $emissor,
    ) {}

    /**
     * @param  list<string>|null  $codigos  null = todas as escolas sem licença válida
     * @return list<array{codigo: string, nome: string, resultado: string, erro: string|null}> resultado: emitida | simulada | saltada | falhou
     *
     * @throws EmissorIndisponivel
     * @throws DadosDeLicencaInvalidos
     */
    public function executar(int $planoId, int $dias, ?array $codigos, bool $simular): array
    {
        return $this->comLog('Emitir licenças de transição', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($planoId, $dias, $codigos, $simular));
    }

    private function executarSemRegisto(int $planoId, int $dias, ?array $codigos, bool $simular): array
    {
        if ($dias < 1) {
            throw new DadosDeLicencaInvalidos(['dias' => 'Os dias de validade têm de ser pelo menos 1.']);
        }
        if (! Plano::query()->whereKey($planoId)->exists()) {
            throw new DadosDeLicencaInvalidos(['plano' => 'O plano não existe.']);
        }
        if (! $simular && ! $this->emissor->disponivel()) {
            throw new EmissorIndisponivel;
        }

        $resultados = [];
        $expiraEm = CarbonImmutable::now()->utc()->addDays($dias);

        foreach ($this->escolas->listar($codigos) as $escola) {
            $tenant = $escola['tenant'];
            $linha = ['codigo' => $tenant->codigo, 'nome' => $tenant->nome, 'resultado' => 'simulada', 'erro' => null];

            // Revogada = bloqueio deliberado (suspensão/cancelamento): a transição não o desfaz.
            if ($escola['motivo'] === 'licença revogada') {
                $resultados[] = ['codigo' => $tenant->codigo, 'nome' => $tenant->nome, 'resultado' => 'saltada', 'erro' => 'Licença revogada: reactive-a no painel da Plataforma.'];

                continue;
            }

            if (! $simular) {
                try {
                    $this->emitir->executar(new EmitirLicencaDTO(
                        planoId: $planoId, escola: $tenant->codigo, expiraEm: $expiraEm, modalidade: Modalidade::ONLINE, motivo: self::MOTIVO,
                    ), null);
                    $linha['resultado'] = 'emitida';
                } catch (DadosDeLicencaInvalidos $e) {
                    $linha['resultado'] = 'falhou';
                    $linha['erro'] = $e->getMessage();
                } catch (Throwable $e) {
                    report($e);
                    $linha['resultado'] = 'falhou';
                    $linha['erro'] = 'Falha inesperada (' . $e::class . '); consulte os logs.';
                }
            }

            $resultados[] = $linha;
        }

        return $resultados;
    }
}
