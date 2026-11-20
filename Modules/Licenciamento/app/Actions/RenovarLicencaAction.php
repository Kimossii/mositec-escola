<?php

namespace Modules\Licenciamento\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Licenciamento\DTOs\RenovarLicencaDTO;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Exceptions\EmissorIndisponivel;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\CicloDeVidaDaLicenca;

/**
 * Renovação: emite uma licença NOVA (novo jti, `iat` estritamente posterior, nova validade) com os direitos da anterior
 * como base. A anterior passa a `substituida`. No SaaS a nova substitui a instalada pelo caminho normal.
 * Só se renova uma licença activa: uma suspensa reactiva-se primeiro e uma cancelada nunca renova. Precisa da chave.
 * Validade por omissão: a partir do fim da anterior (se ainda não expirou) ou de agora, durante os dias do plano.
 */
class RenovarLicencaAction
{
    use LogService;

    public function __construct(
        private readonly CicloDeVidaDaLicenca $ciclo,
        private readonly EmitirLicencaAction $emitir,
    ) {}

    /**
     * @throws EmissorIndisponivel
     * @throws DadosDeLicencaInvalidos
     */
    public function executar(LicencaEmitida $licenca, RenovarLicencaDTO $dto, string $motivo, ?int $autorId): LicencaEmitida
    {
        return $this->comLog('Renovar licença', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($licenca, $dto, $motivo, $autorId));
    }

    private function executarSemRegisto(LicencaEmitida $licenca, RenovarLicencaDTO $dto, string $motivo, ?int $autorId): LicencaEmitida
    {
        $motivo = $this->ciclo->motivo($motivo);

        return DB::transaction(function () use ($licenca, $dto, $motivo, $autorId) {
            $actual = $this->ciclo->bloquear($licenca);
            $this->ciclo->exigirEstado($actual, [EstadoComercial::ACTIVA], 'renovar');

            $agora = CarbonImmutable::now()->utc();
            $expiraEm = $dto->expiraEm;
            if ($expiraEm === null) {
                $plano = Plano::query()->find($actual->plano_id)
                    ?? throw new DadosDeLicencaInvalidos(['plano' => 'O plano da licença já não existe: indique a nova data de expiração.']);
                $base = $actual->expira_em->gt($agora) ? $actual->expira_em : $agora;
                $expiraEm = $base->addDays($plano->validade_dias);
            }

            $limites = [
                'alunos' => $actual->limite_alunos,
                'utilizadores' => $actual->limite_utilizadores,
                'estabelecimentos' => $actual->limite_estabelecimentos,
            ];

            $nova = $this->emitir->executar($this->ciclo->dtoDe($actual, [
                'inicio' => $dto->inicio ?? $agora,
                'expiraEm' => $expiraEm,
                'limites' => array_merge($limites, $dto->limites ?? []),
                'toleranciaDias' => $dto->toleranciaDias ?? $actual->tolerancia_dias,
                'avisoDias' => $dto->avisoDias ?? $actual->aviso_dias,
                'modulos' => $dto->modulos ?? $actual->modulos,
            ]), $autorId);

            $actual->forceFill(['estado_comercial' => EstadoComercial::SUBSTITUIDA])->save();
            $this->ciclo->registar($actual, 'renovada', $motivo, $autorId, [
                'nova_licenca_id' => $nova->id,
                'expira_em_anterior' => $actual->expira_em->toIso8601String(),
                'expira_em_nova' => $nova->expira_em->toIso8601String(),
            ]);

            $licenca->setRawAttributes($actual->getAttributes(), true);

            return $nova;
        });
    }
}
