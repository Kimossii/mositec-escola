<?php

namespace Modules\Matricula\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\GeradorNumeroRegistoMatriculaService;
use Modules\Matricula\Services\ValidadorMatriculaService;
use Modules\Turma\Models\Turma;
use Modules\Core\Traits\LogService;

class CriarMatriculaAction
{
    use LogService;

    public function __construct(
        private GeradorNumeroRegistoMatriculaService $geradorNumeroRegisto,
        private ValidadorMatriculaService $validador,
        private RegistarHistoricoMatriculaAction $registarHistorico,
        private InscreverDisciplinasAutomaticamenteAction $inscreverDisciplinas,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(
        Aluno $aluno,
        MatriculaDTO $dto,
        ?int $utilizadorId = null,
    ): Matricula
    {
        return $this->comLog('Criar matrícula', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($aluno, $dto, $utilizadorId));
    }

    private function executarSemRegisto(
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
            $this->validador->validarSituacaoFinanceira($aluno, $turma);
            $this->inscreverDisciplinas->garantirPlanoCurricularConfirmado($turma);

            $anoLectivoId = $turma->ano_lectivo_id;

            $numeroRegisto = $this->geradorNumeroRegisto->gerar();

            $matricula = Matricula::create([
                'aluno_id' => $aluno->id,
                'turma_id' => $turma->id,
                'ano_lectivo_id' => $anoLectivoId,
                'numero_registo_matricula' => $numeroRegisto,
                'data_matricula' => $dto->dataMatricula ?? $this->relogio->hoje()->toDateString(),
                'estado' => $dto->estado ?? EstadoMatriculaEnum::PENDENTE->value,
                'observacoes' => $dto->observacoes,
                'criado_por' => $utilizadorId,
            ]);

            $this->registarHistorico->executar($matricula, null, $matricula->estado, $utilizadorId);

            $this->inscreverDisciplinas->executar($matricula, $utilizadorId);

            // O mesmo facto que o histórico regista (estado anterior nulo), na mesma transacção.
            MatriculaEstadoAlterado::dispatch(
                (int) $matricula->id,
                null,
                $matricula->estado->value,
                $matricula->data_matricula->toDateString(),
                $utilizadorId,
            );

            return $matricula;
        });
    }
}
