<?php

namespace Modules\Matricula\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Aluno\Models\Aluno;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\GeradorNumeroRegistoMatriculaService;
use Modules\Turma\Models\Turma;

class CriarMatriculaAction
{
    public function __construct(
        private GeradorNumeroRegistoMatriculaService $geradorNumeroRegisto,
    ) {
    }

    public function executar(
        Aluno $aluno,
        MatriculaDTO $dto,
        ?int $utilizadorId = null,
    ): Matricula {
        return DB::transaction(function () use ($aluno, $dto, $utilizadorId) {
            $turma = Turma::query()
                ->with('curso')
                ->findOrFail($dto->turmaId);

            $this->validarTurma($turma, $dto);
            $this->validarEnquadramentoAcademico($aluno, $turma);

            $anoLectivoId = $turma->ano_lectivo_id;

            $numeroRegisto = $this->geradorNumeroRegisto
                ->gerar($anoLectivoId);

            return Matricula::create([
                'aluno_id' => $aluno->id,
                'turma_id' => $turma->id,
                'ano_lectivo_id' => $anoLectivoId,
                'numero_registo_matricula' => $numeroRegisto,
                'data_matricula' => $dto->dataMatricula ?? now()->toDateString(),
                'estado' => $dto->estado ?? EstadoMatriculaEnum::PENDENTE->value,
                'observacoes' => $dto->observacoes,
                'criado_por' => $utilizadorId,
            ]);
        });
    }

    private function validarTurma(Turma $turma, MatriculaDTO $dto): void
    {
        if ($turma->ano_lectivo_id !== $dto->anoLectivoId) {
            throw new \DomainException(
                'A turma não pertence ao ano lectivo seleccionado.'
            );
        }

        if ($turma->estado !== 0) {
            throw new \DomainException(
                'Não é possível matricular um aluno numa turma inactiva.'
            );
        }
    }

    private function validarEnquadramentoAcademico(
        Aluno $aluno,
        Turma $turma
    ): void {
        $query = $aluno->enquadramentosAcademicos()
            ->where('estado', 1);

        if ($turma->curso_id !== null) {
            $query->where('curso_id', $turma->curso_id);
        } else {
            $query->where('nivel_academico_id', $turma->nivel_academico_id);
        }

        if (! $query->exists()) {
            throw new \DomainException(
                'O enquadramento académico do aluno não é compatível com a turma.'
            );
        }
    }
}
