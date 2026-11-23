<?php

namespace Modules\Turma\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Turma\Contracts\DependenciasDoTempoDoTurno;
use Modules\Turma\Models\Turno;

/** Consulta todas as DependenciasDoTempoDoTurno registadas na etiqueta e devolve o primeiro motivo. */
class DependenciasRegistadasDoTurno
{
    public const ETIQUETA = 'turma.dependencias-do-tempo';

    public function __construct(private Container $container)
    {
    }

    public function bloqueiaRemocaoDoTempo(Turno $turno, int $horarioId): ?string
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $dependencia) {
            /** @var DependenciasDoTempoDoTurno $dependencia */
            if (($motivo = $dependencia->bloqueiaRemocao($turno, $horarioId)) !== null) {
                return $motivo;
            }
        }

        return null;
    }
}
