<?php

namespace Modules\Licenciamento\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\Contracts\CatalogoDeTenants;
use Modules\Core\Traits\LogService;
use Modules\Licenciamento\Assinatura\CodecoDeLicenca;
use Modules\Licenciamento\Assinatura\Exceptions\LicencaInvalida;
use Modules\Licenciamento\Documento\DireitosDaLicenca;
use Modules\Licenciamento\DTOs\EmitirLicencaDTO;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Exceptions\EmissorIndisponivel;
use Modules\Licenciamento\Models\EventoDeLicenca;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\EmissorDeLicencas;
use Modules\Licenciamento\Services\ValidaDireitosComerciais;

/**
 * Emite uma licença: copia os direitos do plano (snapshot) com as sobreposições do pedido, assina, guarda e regista o evento.
 * Se a escola é um tenant DESTA instalação (SaaS), instala-a logo pelo mesmo caminho do administrador; se não, só emite o token.
 */
class EmitirLicencaAction
{
    use LogService;

    public function __construct(
        private readonly EmissorDeLicencas $emissor,
        private readonly CodecoDeLicenca $codec,
        private readonly ValidaDireitosComerciais $validador,
        private readonly CatalogoDeTenants $tenants,
        private readonly InstalarLicencaAction $instalar,
    ) {}

    /**
     * @throws EmissorIndisponivel
     * @throws DadosDeLicencaInvalidos
     */
    public function executar(EmitirLicencaDTO $dto, ?int $autorId): LicencaEmitida
    {
        return $this->comLog('Emitir licença', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto, $autorId));
    }

    private function executarSemRegisto(EmitirLicencaDTO $dto, ?int $autorId): LicencaEmitida
    {
        if (! $this->emissor->disponivel()) {
            throw new EmissorIndisponivel;
        }

        $plano = Plano::query()->find($dto->planoId)
            ?? throw new DadosDeLicencaInvalidos(['plano' => 'O plano não existe.']);

        $modalidade = $dto->modalidade ?? $plano->modalidade;
        // Sem microssegundos: `emitida_em` e `iat` só guardam segundos, a comparação tem de ser à mesma resolução.
        $agora = CarbonImmutable::now()->utc()->startOfSecond();
        if ($dto->emitidaDepoisDe !== null && $agora->lte($dto->emitidaDepoisDe)) {
            // Por construção, uma licença que substitui outra nunca tem `iat` igual ou anterior (o guarda anti-replay recusá-la-ia).
            $agora = $dto->emitidaDepoisDe->utc()->addSecond();
        }
        $inicio = ($dto->inicio ?? $agora)->utc();
        $expiraEm = ($dto->expiraEm ?? $inicio->addDays($plano->validade_dias))->utc();

        $limites = [];
        foreach (ValidaDireitosComerciais::CHAVES_LIMITES as $chave) {
            $limites[$chave] = $dto->limites !== null && array_key_exists($chave, $dto->limites)
                ? $dto->limites[$chave]
                : $plano->{'limite_' . $chave};
        }

        $tolerancia = $dto->toleranciaDias ?? $plano->tolerancia_dias ?? (int) config('licenciamento.tolerancia_dias_padrao');
        $aviso = $dto->avisoDias ?? $plano->aviso_dias ?? (int) config('licenciamento.aviso_dias_padrao');
        $modulos = array_values(array_unique($dto->modulos ?? $plano->modulos));

        $erros = $this->validador->erros($limites, $tolerancia, $aviso, $modulos);
        $escola = $this->texto($dto->escola);
        // Só o ONLINE (SaaS alojado aqui) se resolve e instala num tenant local. Os códigos de escola são sequenciais por
        // instalação (MOSI-000001…), por isso o de uma escola self-hosted pode coincidir com o de uma escola local: LOCAL/HIBRIDO
        // nunca tocam nos direitos de nenhum tenant desta instalação. Código canónico: assina-se com o do catálogo (ignora maiúsculas/espaços).
        $tenant = $modalidade === Modalidade::ONLINE && $escola !== null ? $this->tenants->porCodigo($escola) : null;
        $escola = $tenant?->codigo ?? ($escola !== null ? strtoupper($escola) : null);
        $instalacao = $this->texto($dto->instalacao);
        $ligacao = $dto->ligacaoObrigatoria ?? ($modalidade !== Modalidade::ONLINE);

        if ($modalidade === Modalidade::ONLINE) {
            $instalacao = null; // o online liga-se à escola
            $ligacao = false;
            if ($escola === null) {
                $erros['escola'] = 'Indique a escola da licença.';
            } elseif ($tenant === null) {
                $erros['escola'] = 'Esta escola não existe nesta instalação: uma licença online só se emite para escolas aqui alojadas.';
            }
        } elseif ($ligacao) {
            if ($instalacao === null) {
                $erros['instalacao'] = 'Indique o código da instalação (ou desligue a ligação à instalação).';
            }
        } else {
            $instalacao = null;
        }

        if ($erros !== []) {
            throw new DadosDeLicencaInvalidos($erros);
        }

        $id = (string) Str::uuid();
        $direitos = new DireitosDaLicenca(
            id: $id, escola: $escola, instalacao: $instalacao, emitidaEm: $agora, inicio: $inicio, expiraEm: $expiraEm,
            toleranciaDias: $tolerancia, avisoDias: $aviso, limites: $limites, modulos: $modulos, modalidade: $modalidade,
        );
        $claims = $direitos->paraClaims();

        try {
            DireitosDaLicenca::deClaims($claims); // o mesmo crivo que o verificador vai aplicar
        } catch (LicencaInvalida $e) {
            throw new DadosDeLicencaInvalidos(['licenca' => $e->getMessage()]);
        }

        $token = $this->codec->assinar($claims, $this->emissor->chavePrivada(), $this->emissor->kid());

        return DB::transaction(function () use ($plano, $id, $escola, $instalacao, $ligacao, $modalidade, $limites, $tolerancia, $aviso, $modulos, $agora, $inicio, $expiraEm, $token, $tenant, $autorId, $dto) {
            $licenca = new LicencaEmitida;
            $licenca->id = $id;
            $licenca->forceFill([
                'plano_id' => $plano->id,
                'escola' => $escola,
                'tenant_local_id' => $tenant?->id,
                'instalacao' => $instalacao,
                'ligacao_obrigatoria' => $ligacao,
                'modalidade' => $modalidade,
                'estado_comercial' => EstadoComercial::ACTIVA,
                'limite_alunos' => $limites['alunos'],
                'limite_utilizadores' => $limites['utilizadores'],
                'limite_estabelecimentos' => $limites['estabelecimentos'],
                'tolerancia_dias' => $tolerancia,
                'aviso_dias' => $aviso,
                'modulos' => $modulos,
                'emitida_em' => $agora,
                'inicio' => $inicio,
                'expira_em' => $expiraEm,
                'token' => $token,
            ])->save();

            EventoDeLicenca::query()->create([
                'licenca_id' => $id,
                'tipo' => 'emitida',
                'super_admin_id' => $autorId,
                'motivo' => $dto->motivo,
                'detalhe' => [
                    'plano_id' => $plano->id,
                    'escola' => $escola,
                    'instalacao' => $instalacao,
                    'modalidade' => $modalidade->slug(),
                    'expira_em' => $expiraEm->toIso8601String(),
                    'instalada_no_saas' => $tenant !== null,
                    'substitui_licenca_id' => $dto->substituiLicencaId,
                ],
            ]);

            $this->substituirAnteriores($licenca, $modalidade, $escola, $instalacao, $dto->substituiLicencaId, $autorId);

            if ($tenant !== null) {
                $this->instalar->executar($tenant->id, $tenant->codigo, $token);
            }

            return $licenca;
        });
    }

    /**
     * Uma licença nova para o mesmo alvo (escola no ONLINE; instalação no LOCAL/HIBRIDO) torna `substituida` as anteriores
     * activas/suspensas, para haver uma só em vigor. A que está a ser renovada/estendida fica de fora: esses fluxos marcam-na e registam-na.
     */
    private function substituirAnteriores(LicencaEmitida $nova, Modalidade $modalidade, ?string $escola, ?string $instalacao, ?string $emSubstituicaoId, ?int $autorId): void
    {
        $alvoEscola = $modalidade === Modalidade::ONLINE;
        $valor = $alvoEscola ? $escola : $instalacao;
        if ($valor === null) {
            return;
        }

        $anteriores = LicencaEmitida::query()
            ->whereKeyNot($nova->id)
            ->when($emSubstituicaoId !== null, fn ($q) => $q->whereKeyNot($emSubstituicaoId))
            ->whereIn('estado_comercial', [EstadoComercial::ACTIVA->value, EstadoComercial::SUSPENSA->value])
            ->where($alvoEscola ? 'escola' : 'instalacao', $valor)
            ->where('modalidade', $alvoEscola ? '=' : '!=', Modalidade::ONLINE->value)
            ->lockForUpdate()
            ->get();

        foreach ($anteriores as $anterior) {
            $anterior->forceFill(['estado_comercial' => EstadoComercial::SUBSTITUIDA])->save();
            EventoDeLicenca::query()->create([
                'licenca_id' => $anterior->id,
                'tipo' => 'substituida',
                'super_admin_id' => $autorId,
                'motivo' => 'Substituída por uma licença nova para o mesmo destino.',
                'detalhe' => ['nova_licenca_id' => $nova->id],
            ]);
        }
    }

    private function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
