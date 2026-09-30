<?php

namespace Modules\Matricula\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Aluno\Models\Aluno;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\GeradorNumeroRegistoMatriculaService;
use Modules\Matricula\Services\ValidadorMatriculaService;
use Modules\Turma\Models\Turma;

class CriarMatriculaAction
{
    public function __construct(
        private GeradorNumeroRegistoMatriculaService $geradorNumeroRegisto,
        private ValidadorMatriculaService $validador,
        private RegistarHistoricoMatriculaAction $registarHistorico,
        private InscreverDisciplinasAutomaticamenteAction $inscreverDisciplinas,
    ) {
    }

    public function executar(
        Aluno $aluno,
        MatriculaDTO $dto,
        ?int $utilizadorId = null,
    ): Matricula {
        return DB::transaction(function () use ($aluno, $dto, $utilizadorId) {
            $turma = Turma::query()
                ->with(['curso', 'anoLectivo'])
                ->findOrFail($dto->turmaId);

            $this->validador->validarTurma($turma, $dto->anoLectivoId);
            $this->validador->garantirEnquadramentoAcademico($aluno, $turma, $utilizadorId);
            $this->validador->validarMatriculaNaoDuplicada($aluno, $turma);
            $this->inscreverDisciplinas->garantirPlanoCurricularConfirmado($turma);

            $anoLectivoId = $turma->ano_lectivo_id;

            $numeroRegisto = $this->geradorNumeroRegisto->gerar();

            $matricula = Matricula::create([
                'aluno_id' => $aluno->id,
                'turma_id' => $turma->id,
                'ano_lectivo_id' => $anoLectivoId,
                'numero_registo_matricula' => $numeroRegisto,
                'data_matricula' => $dto->dataMatricula ?? now()->toDateString(),
                'estado' => $dto->estado ?? EstadoMatriculaEnum::PENDENTE->value,
                'observacoes' => $dto->observacoes,
                'criado_por' => $utilizadorId,
            ]);

            $this->registarHistorico->executar($matricula, null, $matricula->estado, $utilizadorId);

            $this->inscreverDisciplinas->executar($matricula, $utilizadorId);

            return $matricula;
        });
    }
}
