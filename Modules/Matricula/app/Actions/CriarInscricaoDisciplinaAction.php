<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class CriarInscricaoDisciplinaAction
{
    public function executar(
        Matricula $matricula,
        PlanoCurricularDisciplina $planoCurricularDisciplina,
        ?int $utilizadorId = null,
    ): InscricaoDisciplina {
        if ($matricula->estado->eTerminal()) {
            throw ValidationException::withMessages([
                'plano_curricular_disciplina_id' => 'Não é possível inscrever numa matrícula já concluída, cancelada ou transferida.',
            ]);
        }

        $jaInscrito = InscricaoDisciplina::query()
            ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA->value)
            ->whereHas('planoCurricularDisciplina', fn ($query) => $query->where('disciplina_id', $planoCurricularDisciplina->disciplina_id))
            ->whereHas('matricula', function ($query) use ($matricula) {
                $query->where('aluno_id', $matricula->aluno_id)
                    ->whereIn('estado', [EstadoMatriculaEnum::PENDENTE->value, EstadoMatriculaEnum::ACTIVA->value]);
            })
            ->exists();

        if ($jaInscrito) {
            throw ValidationException::withMessages([
                'plano_curricular_disciplina_id' => 'O aluno já está inscrito nesta disciplina.',
            ]);
        }

        return InscricaoDisciplina::create([
            'matricula_id' => $matricula->id,
            'plano_curricular_disciplina_id' => $planoCurricularDisciplina->id,
            'data_inscricao' => now()->toDateString(),
            'estado' => EstadoInscricaoDisciplinaEnum::INSCRITA->value,
            'criado_por' => $utilizadorId,
        ]);
    }
}
