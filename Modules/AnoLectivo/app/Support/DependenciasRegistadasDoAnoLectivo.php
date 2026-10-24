<?php

namespace Modules\AnoLectivo\Support;

use Illuminate\Contracts\Container\Container;
use Modules\AnoLectivo\Contracts\DependenciasDoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;

/**
 * Consulta todas as DependenciasDoAnoLectivo registadas na etiqueta e devolve o primeiro motivo.
 */
class DependenciasRegistadasDoAnoLectivo
{
    public const ETIQUETA = 'anolectivo.dependencias';

    public function __construct(private Container $container)
    {
    }

    public function bloqueiaAlteracaoDeInicio(AnoLectivo $anoLectivo): ?string
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $dependencia) {
            /** @var DependenciasDoAnoLectivo $dependencia */
            if (($motivo = $dependencia->bloqueiaAlteracaoDeInicio($anoLectivo)) !== null) {
                return $motivo;
            }
        }

        return null;
    }

    public function bloqueiaEliminacao(AnoLectivo $anoLectivo): ?string
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $dependencia) {
            /** @var DependenciasDoAnoLectivo $dependencia */
            if (($motivo = $dependencia->bloqueiaEliminacao($anoLectivo)) !== null) {
                return $motivo;
            }
        }

        return null;
    }
}
