<?php

namespace Modules\Docente\Services;

use Modules\Aluno\Models\Aluno;
use Modules\Core\Contracts\VerificaProfessorLeccionaAluno;
use Modules\Core\Enums\Estado;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

class VerificaProfessorLeccionaAlunoService implements VerificaProfessorLeccionaAluno
{
    public function lecciona(int $pessoaDoProfessorId, int $pessoaDoAlunoId): bool
    {
        $turmasDoAluno = Matricula::query()
            ->select('turma_id')
            ->where('estado', EstadoMatriculaEnum::ACTIVA->value)
            ->whereIn('aluno_id', Aluno::query()->select('id')->where('dados_pessoa_id', $pessoaDoAlunoId));

        // Mesma noção de «em vigor» do resto do módulo: turma não eliminada e ano lectivo ainda não terminado.
        return AtribuicaoDocente::query()
            ->emVigor(now())
            ->whereHas('turma')
            ->whereHas('anoLectivo', fn ($ano) => $ano->whereDate('data_fim', '>=', now()->toDateString()))
            ->whereHas('docente', fn ($q) => $q->where('dados_pessoa_id', $pessoaDoProfessorId)->where('estado', Estado::ATIVO->value))
            ->whereIn('turma_id', $turmasDoAluno)
            ->exists();
    }
}
