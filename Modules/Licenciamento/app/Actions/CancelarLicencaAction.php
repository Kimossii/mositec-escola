<?php

namespace Modules\Licenciamento\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Services\CicloDeVidaDaLicenca;

/**
 * Activa|Suspensa -> Cancelada (definitivo). Revoga a licença instalada nesta instalação (SaaS, efeito imediato).
 *
 * LIMITE: num token offline/self-hosted só muda o registo do emissor (ver SuspenderLicencaAction). Não precisa de chave.
 */
class CancelarLicencaAction
{
    use LogService;

    public function __construct(private readonly CicloDeVidaDaLicenca $ciclo) {}

    public function executar(LicencaEmitida $licenca, string $motivo, ?int $autorId): LicencaEmitida
    {
        return $this->comLog('Cancelar licença', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($licenca, $motivo, $autorId));
    }

    /** @throws DadosDeLicencaInvalidos */
    private function executarSemRegisto(LicencaEmitida $licenca, string $motivo, ?int $autorId): LicencaEmitida
    {
        $motivo = $this->ciclo->motivo($motivo);

        return DB::transaction(function () use ($licenca, $motivo, $autorId) {
            $actual = $this->ciclo->bloquear($licenca);
            $this->ciclo->exigirEstado($actual, [EstadoComercial::ACTIVA, EstadoComercial::SUSPENSA], 'cancelar');

            $actual->forceFill(['estado_comercial' => EstadoComercial::CANCELADA])->save();
            $this->ciclo->revogarLocalmente($actual);
            $this->ciclo->registar($actual, 'cancelada', $motivo, $autorId);

            $licenca->setRawAttributes($actual->getAttributes(), true);

            return $licenca;
        });
    }
}
