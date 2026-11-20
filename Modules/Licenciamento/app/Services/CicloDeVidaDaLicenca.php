<?php

namespace Modules\Licenciamento\Services;

use Carbon\CarbonImmutable;
use Modules\Licenciamento\DTOs\EmitirLicencaDTO;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Models\EventoDeLicenca;
use Modules\Licenciamento\Models\LicencaEmitida;

/**
 * Peças comuns das transições do ciclo de vida de uma licença emitida: motivo, bloqueio da linha, transições
 * permitidas, evento append-only e revogação local (só tem efeito se a escola é desta instalação).
 */
class CicloDeVidaDaLicenca
{
    public const MOTIVO_MAX = 255;

    public function __construct(private readonly DireitosService $direitos) {}

    /** @throws DadosDeLicencaInvalidos */
    public function motivo(string $motivo): string
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new DadosDeLicencaInvalidos(['motivo' => 'O motivo é obrigatório.']);
        }

        if (mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new DadosDeLicencaInvalidos(['motivo' => 'O motivo não pode exceder ' . self::MOTIVO_MAX . ' caracteres.']);
        }

        return $motivo;
    }

    /** Lê o estado fresco com bloqueio (o objecto recebido pode estar desactualizado). Só dentro de uma transacção. */
    public function bloquear(LicencaEmitida $licenca): LicencaEmitida
    {
        return LicencaEmitida::query()->lockForUpdate()->findOrFail($licenca->getKey());
    }

    /**
     * @param  list<EstadoComercial>  $permitidos
     *
     * @throws DadosDeLicencaInvalidos
     */
    public function exigirEstado(LicencaEmitida $licenca, array $permitidos, string $accao): void
    {
        if ($licenca->estado_comercial === EstadoComercial::SUBSTITUIDA) {
            throw new DadosDeLicencaInvalidos(['estado' => "Não é possível {$accao} esta licença: foi substituída por uma licença mais recente."]);
        }

        if (! in_array($licenca->estado_comercial, $permitidos, true)) {
            throw new DadosDeLicencaInvalidos(['estado' => "Não é possível {$accao} uma licença {$licenca->estado_comercial->label()}."]);
        }
    }

    /** @param array<string,mixed> $detalhe nunca o token nem chaves */
    public function registar(LicencaEmitida $licenca, string $tipo, string $motivo, ?int $autorId, array $detalhe = []): void
    {
        EventoDeLicenca::query()->create([
            'licenca_id' => $licenca->id,
            'tipo' => $tipo,
            'super_admin_id' => $autorId,
            'motivo' => $motivo,
            'detalhe' => $detalhe === [] ? null : $detalhe,
        ]);
    }

    /** Revoga a licença instalada nesta instalação (SaaS): efeito imediato. Sem tenant local ou com outra licença instalada, nada a fazer. */
    public function revogarLocalmente(LicencaEmitida $licenca): void
    {
        $this->marcarLocal($licenca, CarbonImmutable::now()->utc());
    }

    public function levantarRevogacaoLocal(LicencaEmitida $licenca): void
    {
        $this->marcarLocal($licenca, null);
    }

    private function marcarLocal(LicencaEmitida $licenca, ?CarbonImmutable $quando): void
    {
        if ($licenca->tenant_local_id === null) {
            return;
        }

        $registo = DireitosLocais::query()
            ->where('tenant_id', $licenca->tenant_local_id)
            ->where('licenca_id', $licenca->id)
            ->lockForUpdate()
            ->first();

        if ($registo === null) {
            return;
        }

        $registo->revogada_em = $quando;
        $registo->save();
        $this->direitos->esquecer();
    }

    /** Pedido de emissão que repete os direitos da licença (base de renovações e extensões). */
    public function dtoDe(LicencaEmitida $licenca, array $sobrepor = []): EmitirLicencaDTO
    {
        if ($licenca->plano_id === null) {
            throw new DadosDeLicencaInvalidos(['plano' => 'A licença não tem plano associado.']);
        }

        return new EmitirLicencaDTO(...array_merge([
            'planoId' => $licenca->plano_id,
            'escola' => $licenca->escola,
            'instalacao' => $licenca->instalacao,
            'inicio' => $licenca->inicio,
            'expiraEm' => $licenca->expira_em,
            'limites' => [
                'alunos' => $licenca->limite_alunos,
                'utilizadores' => $licenca->limite_utilizadores,
                'estabelecimentos' => $licenca->limite_estabelecimentos,
            ],
            'toleranciaDias' => $licenca->tolerancia_dias,
            'avisoDias' => $licenca->aviso_dias,
            'modulos' => $licenca->modulos,
            'modalidade' => $licenca->modalidade,
            'ligacaoObrigatoria' => $licenca->ligacao_obrigatoria,
            'emitidaDepoisDe' => $licenca->emitida_em,
            'substituiLicencaId' => $licenca->id,
        ], $sobrepor));
    }
}
