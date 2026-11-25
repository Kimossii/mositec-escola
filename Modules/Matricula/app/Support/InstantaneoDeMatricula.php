<?php

namespace Modules\Matricula\Support;

use Modules\Matricula\Models\Matricula;

/** Campos de negócio da matrícula para o rasto de auditoria (o estado tem o seu histórico próprio). */
class InstantaneoDeMatricula
{
    /** @return array<string, mixed> */
    public static function de(Matricula $matricula): array
    {
        return [
            'aluno_id' => (int) $matricula->aluno_id,
            'turma_id' => (int) $matricula->turma_id,
            'ano_lectivo_id' => (int) $matricula->ano_lectivo_id,
            'numero_registo_matricula' => $matricula->numero_registo_matricula,
            'data_matricula' => $matricula->data_matricula?->toDateString(),
            'observacoes' => $matricula->observacoes,
        ];
    }
}
