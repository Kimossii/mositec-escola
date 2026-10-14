<?php

namespace Modules\Matricula\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\ValidadorMatriculaService;
use Modules\Matricula\Support\DependenciasRegistadasDaMatricula;
use Modules\Turma\Models\Turma;

class AtualizarMatriculaAction
{
    public function __construct(
        private ValidadorMatriculaService $validador,
        private DependenciasRegistadasDaMatricula $dependencias,
    ) {
    }

    public function executar(
        Matricula $matricula,
        MatriculaDTO $dto,
        ?int $utilizadorId = null,
    ): Matricula {
        return DB::transaction(function () use ($matricula, $dto, $utilizadorId) {
            // Bloqueada e relida antes de tudo: quem cria registos dependentes desta matrícula (ex.: propinas)
            // bloqueia-a também, por isso a verificação das dependências e a gravação não podem ser intercaladas.
            $matricula = Matricula::query()->whereKey($matricula->getKey())->lockForUpdate()->firstOrFail();

            if ($matricula->estado->eTerminal()) {
                throw ValidationException::withMessages([
                    'estado' => 'Não é possível editar uma matrícula já concluída, cancelada ou transferida.',
                ]);
            }

            $turma = Turma::query()
                ->with(['curso', 'anoLectivo'])
                ->findOrFail($dto->turmaId);

            $this->validador->validarTurma($turma, $dto->anoLectivoId);

            $aluno = $matricula->aluno;

            $this->validador->garantirEnquadramentoAcademico($aluno, $turma, $utilizadorId);
            $this->validador->validarMatriculaNaoDuplicada($aluno, $turma, $matricula->id);

            $dataNova = CarbonImmutable::parse($dto->dataMatricula)->toDateString();
            $motivo = $this->dependencias->bloqueiaAlteracao($matricula, (int) $turma->id, (int) $turma->ano_lectivo_id, $dataNova);

            if ($motivo !== null) {
                $mudaTurmaOuAno = (int) $turma->id !== (int) $matricula->turma_id
                    || (int) $turma->ano_lectivo_id !== (int) $matricula->ano_lectivo_id;

                throw ValidationException::withMessages([$mudaTurmaOuAno ? 'turma_id' : 'data_matricula' => $motivo]);
            }

            $matricula->update([
                'turma_id' => $turma->id,
                'ano_lectivo_id' => $turma->ano_lectivo_id,
                'data_matricula' => $dto->dataMatricula,
                'observacoes' => $dto->observacoes,
                'editado_por' => $utilizadorId,
            ]);

            return $matricula->fresh();
        });
    }
}
