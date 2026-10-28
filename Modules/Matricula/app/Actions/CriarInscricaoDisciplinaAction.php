<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Core\Traits\LogService;

class CriarInscricaoDisciplinaAction
{
    use LogService;

    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(
        Matricula $matricula,
        PlanoCurricularDisciplina $planoCurricularDisciplina,
        ?int $utilizadorId = null,
    ): InscricaoDisciplina
    {
        return $this->comLog('Criar inscrição disciplina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($matricula, $planoCurricularDisciplina, $utilizadorId));
    }

    private function executarSemRegisto(
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
            'data_inscricao' => $this->relogio->hoje()->toDateString(),
            'estado' => EstadoInscricaoDisciplinaEnum::INSCRITA->value,
            'criado_por' => $utilizadorId,
        ]);
    }
}
