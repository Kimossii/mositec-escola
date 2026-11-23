<?php

namespace Modules\Core\Support\SemFrequencia;

use Modules\Core\Contracts\ConsultaFaltasDoAluno;

/** Sem o módulo Frequencia não há faltas: zeros e «sem chamadas», para que o boletim as omita. O módulo sobrepõe este binding. */
class FaltasDoAlunoVazias implements ConsultaFaltasDoAluno
{
    public function resumoDaMatricula(int $matriculaId): array
    {
        return [
            'unidade' => 'aulas',
            'total' => 0,
            'justificadas' => 0,
            'injustificadas' => 0,
            'com_chamadas' => false,
            'por_disciplina' => [],
            'por_periodo' => [],
        ];
    }
}
