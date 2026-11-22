<?php

namespace Modules\Licenciamento\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Services\CicloDeVidaDaLicenca;

/** Suspensa -> Activa; levanta a revogação local (SaaS). Uma licença cancelada nunca se reactiva. Não precisa de chave. */
class ReactivarLicencaAction
{
    use LogService;

    public function __construct(private readonly CicloDeVidaDaLicenca $ciclo) {}

    public function executar(LicencaEmitida $licenca, string $motivo, ?int $autorId): LicencaEmitida
    {
        return $this->comLog('Reactivar licença', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($licenca, $motivo, $autorId));
    }

    /** @throws DadosDeLicencaInvalidos */
    private function executarSemRegisto(LicencaEmitida $licenca, string $motivo, ?int $autorId): LicencaEmitida
    {
        $motivo = $this->ciclo->motivo($motivo);

        return DB::transaction(function () use ($licenca, $motivo, $autorId) {
            $actual = $this->ciclo->bloquear($licenca);
            $this->ciclo->exigirEstado($actual, [EstadoComercial::SUSPENSA], 'reactivar');

            $actual->forceFill(['estado_comercial' => EstadoComercial::ACTIVA])->save();
            $this->ciclo->levantarRevogacaoLocal($actual);
            $this->ciclo->registar($actual, 'reactivada', $motivo, $autorId);

            $licenca->setRawAttributes($actual->getAttributes(), true);

            return $licenca;
        });
    }
}
