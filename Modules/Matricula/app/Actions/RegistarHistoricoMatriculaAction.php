<?php

namespace Modules\Matricula\Actions;

use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;

class RegistarHistoricoMatriculaAction
{
    public function executar(
        Matricula $matricula,
        ?EstadoMatriculaEnum $estadoAnterior,
        EstadoMatriculaEnum $estadoNovo,
        ?int $utilizadorId = null,
        ?string $dataEfeito = null,
        ?string $motivo = null,
        ?array $detalhe = null,
    ): void {
        MatriculaHistorico::create([
            'matricula_id' => $matricula->id,
            'estado_anterior' => $estadoAnterior?->value,
            'estado_novo' => $estadoNovo->value,
            'utilizador_id' => $utilizadorId,
            'data_efeito' => $dataEfeito,
            'motivo' => $motivo,
            'detalhe' => $detalhe,
        ]);
    }
}
