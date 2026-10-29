<?php

namespace Modules\Core\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Modules\Core\Contracts\DependenciasDoUtilizador;

/**
 * Consulta todas as DependenciasDoUtilizador registadas na etiqueta e devolve o primeiro motivo.
 */
class DependenciasRegistadasDoUtilizador
{
    public const ETIQUETA = 'utilizador.dependencias';

    public function __construct(private Container $container)
    {
    }

    public function bloqueiaEliminacao(Authenticatable $utilizador): ?string
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $dependencia) {
            /** @var DependenciasDoUtilizador $dependencia */
            if (($motivo = $dependencia->bloqueiaEliminacao($utilizador)) !== null) {
                return $motivo;
            }
        }

        return null;
    }
}
