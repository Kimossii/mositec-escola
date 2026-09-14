<?php

namespace Modules\Matricula\Services;

use Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

class ValidadorMatriculaService
{
    public function validarTurma(Turma $turma, int $anoLectivoId): void
    {
        if ($turma->ano_lectivo_id !== $anoLectivoId) {
            throw new \DomainException(
                'A turma não pertence ao ano lectivo seleccionado.'
            );
        }

        if ($turma->estado !== Estado::ATIVO->value) {
            throw new \DomainException(
                'Não é possível utilizar uma turma inactiva.'
            );
        }
    }

    public function validarEnquadramentoAcademico(Aluno $aluno, Turma $turma): void
    {
        $query = $aluno->enquadramentosAcademicos()
            ->where('estado', EstadoEnquadramentoAcademicoEnum::ACTIVO->value);

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

    public function validarMatriculaNaoDuplicada(
        Aluno $aluno,
        Turma $turma,
        ?int $ignorarMatriculaId = null,
    ): void {
        $query = Matricula::query()
            ->where('aluno_id', $aluno->id)
            ->whereIn('estado', [
                EstadoMatriculaEnum::PENDENTE->value,
                EstadoMatriculaEnum::ACTIVA->value,
            ])
            ->whereHas('turma', function ($query) use ($turma) {
                if ($turma->curso_id !== null) {
                    $query->where('curso_id', $turma->curso_id);
                } else {
                    $query->whereNull('curso_id')
                        ->where('nivel_academico_id', $turma->nivel_academico_id);
                }
            });

        if ($ignorarMatriculaId !== null) {
            $query->where('id', '!=', $ignorarMatriculaId);
        }

        if ($query->exists()) {
            throw new \DomainException(
                'O aluno já possui uma matrícula activa ou pendente neste contexto académico.'
            );
        }
    }
}
