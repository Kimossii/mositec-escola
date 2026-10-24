<?php

namespace Modules\Matricula\Support;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Matricula\Contracts\DependenciasDaMatricula;
use Modules\Matricula\Models\Matricula;

/**
 * Consulta todas as DependenciasDaMatricula registadas na etiqueta e devolve o primeiro motivo.
 * Sem nenhuma registada (outro módulo desactivado), nada bloqueia.
 */
class DependenciasRegistadasDaMatricula
{
    public const ETIQUETA = 'matricula.dependencias';

    public function __construct(private Container $container)
    {
    }

    public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $dependencia) {
            if (! $dependencia instanceof DependenciasDaMatricula) {
                throw new LogicException('O serviço registado na etiqueta ' . self::ETIQUETA . ' (' . get_debug_type($dependencia) . ') tem de implementar ' . DependenciasDaMatricula::class . '.');
            }

            if (($motivo = $dependencia->bloqueiaAlteracao($matricula, $turmaNovaId, $anoLectivoNovoId, $dataMatriculaNova)) !== null) {
                return $motivo;
            }
        }

        return null;
    }
}
