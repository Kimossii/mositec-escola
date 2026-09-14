<?php

namespace Modules\Aluno\Actions;

use Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum;
use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Models\AlunoEnquadramentoAcademico;

class CriarEnquadramentoAcademicoAlunoAction
{
    public function executar(
        Aluno $aluno,
        ?int $cursoId = null,
        ?int $nivelAcademicoId = null,
        ?int $utilizadorId = null,
    ): AlunoEnquadramentoAcademico {
        if (($cursoId === null) === ($nivelAcademicoId === null)) {
            throw new \InvalidArgumentException(
                'O aluno deve ser enquadrado por um curso ou por um nível académico.'
            );
        }

        $query = $aluno->enquadramentosAcademicos()
            ->where('estado', EstadoEnquadramentoAcademicoEnum::ACTIVO->value);

        if ($cursoId !== null) {
            $query->where('curso_id', $cursoId);
        } else {
            $query->where('nivel_academico_id', $nivelAcademicoId);
        }

        $enquadramento = $query->first();

        if ($enquadramento) {
            return $enquadramento;
        }

        return AlunoEnquadramentoAcademico::create([
            'aluno_id' => $aluno->id,
            'curso_id' => $cursoId,
            'nivel_academico_id' => $nivelAcademicoId,
            'data_inicio' => now()->toDateString(),
            'estado' => EstadoEnquadramentoAcademicoEnum::ACTIVO->value,
            'criado_por' => $utilizadorId,
        ]);
    }
}
