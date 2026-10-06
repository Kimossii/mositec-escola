<?php

namespace Modules\Matricula\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;

class RenovarMatriculaAction
{
    public function __construct(
        private AlterarEstadoMatriculaAction $alterarEstado,
        private CriarMatriculaAction $criarMatricula,
    ) {
    }

    /**
     * Conclui a matrícula anterior (se ainda estiver activa) e cria uma nova
     * para a turma seguinte — mesmo curso, próximo nível (por `ordem`), no
     * ano lectivo seguinte. Sem `turmaId`, tenta sugerir automaticamente;
     * se não conseguir, pede para indicar a turma manualmente.
     */
    public function executar(
        Matricula $matriculaAnterior,
        ?int $turmaId = null,
        ?int $utilizadorId = null,
    ): Matricula {
        return DB::transaction(function () use ($matriculaAnterior, $turmaId, $utilizadorId) {
            if ($matriculaAnterior->estado === EstadoMatriculaEnum::ACTIVA) {
                $matriculaAnterior = $this->alterarEstado->executar(
                    $matriculaAnterior,
                    EstadoMatriculaEnum::CONCLUIDA,
                    $utilizadorId,
                );
            } elseif ($matriculaAnterior->estado !== EstadoMatriculaEnum::CONCLUIDA) {
                throw ValidationException::withMessages([
                    'matricula' => 'Só é possível renovar uma matrícula activa ou já concluída.',
                ]);
            }

            $turma = $turmaId !== null
                ? Turma::findOrFail($turmaId)
                : $this->sugerirProximaTurma($matriculaAnterior);

            if ($turma === null) {
                throw ValidationException::withMessages([
                    'turma_id' => 'Não foi possível sugerir automaticamente a turma seguinte — indique-a manualmente.',
                ]);
            }

            $this->concluirEnquadramentoDeNivelSeMudou($matriculaAnterior, $turma);

            $dto = new MatriculaDTO(
                turmaId: $turma->id,
                anoLectivoId: $turma->ano_lectivo_id,
                dataMatricula: null,
                estado: null,
                observacoes: null,
            );

            return $this->criarMatricula->executar($matriculaAnterior->aluno, $dto, $utilizadorId);
        });
    }

    /**
     * Sem Curso (Ensino Geral/Técnico), o enquadramento é por Nível e é
     * exclusivo — ao progredir para o nível seguinte, o antigo tem de ser
     * concluído, senão a nova matrícula pareceria incompatível. Com Curso, a
     * compatibilidade já ignora o Nível, por isso não há nada a fazer aqui.
     */
    private function concluirEnquadramentoDeNivelSeMudou(Matricula $matriculaAnterior, Turma $turmaNova): void
    {
        $turmaAnterior = $matriculaAnterior->turma;

        if ($turmaAnterior->curso_id !== null) {
            return;
        }

        if ($turmaAnterior->nivel_academico_id === $turmaNova->nivel_academico_id) {
            return;
        }

        $matriculaAnterior->aluno->enquadramentosAcademicos()
            ->where('estado', EstadoEnquadramentoAcademicoEnum::ACTIVO->value)
            ->where('nivel_academico_id', $turmaAnterior->nivel_academico_id)
            ->update([
                'estado' => EstadoEnquadramentoAcademicoEnum::CONCLUIDO->value,
                'data_fim' => now()->toDateString(),
            ]);
    }

    private function sugerirProximaTurma(Matricula $matriculaAnterior): ?Turma
    {
        $turmaAnterior = $matriculaAnterior->turma;
        $anoLectivoAnterior = $matriculaAnterior->anoLectivo;
        $nivelAnterior = $turmaAnterior->nivelAcademico;

        $anoLectivoSeguinte = AnoLectivo::where('estabelecimento_id', $anoLectivoAnterior->estabelecimento_id)
            ->where('data_inicio', '>', $anoLectivoAnterior->data_inicio)
            ->orderBy('data_inicio')
            ->first();

        if ($anoLectivoSeguinte === null) {
            return null;
        }

        $nivelSeguinte = NivelAcademico::where('estabelecimento_id', $anoLectivoAnterior->estabelecimento_id)
            ->where('etapa_ensino', $nivelAnterior->etapa_ensino)
            ->where('ordem', $nivelAnterior->ordem + 1)
            ->first();

        if ($nivelSeguinte === null) {
            return null;
        }

        return Turma::where('ano_lectivo_id', $anoLectivoSeguinte->id)
            ->where('curso_id', $turmaAnterior->curso_id)
            ->where('nivel_academico_id', $nivelSeguinte->id)
            ->where('estado', Estado::ATIVO->value)
            ->first();
    }
}
