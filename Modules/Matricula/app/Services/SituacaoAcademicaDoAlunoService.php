<?php

namespace Modules\Matricula\Services;

use Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Contracts\ProcuraSituacaoAcademicaDoAluno;
use Modules\Core\DTO\SituacaoAcademicaDoAluno;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

/**
 * Implementação, no módulo Matricula, do contrato usado pelo Usuario. Turma = a da matrícula ACTIVA mais
 * recente (com o ano lectivo); curso = o do enquadramento académico activo, ou o da turma. Tudo
 * lido pelos models (scope de tenant): registos de outro tenant não aparecem.
 */
class SituacaoAcademicaDoAlunoService implements ProcuraSituacaoAcademicaDoAluno
{
    public function procurar(string $numeroMatricula): SituacaoAcademicaDoAluno
    {
        $aluno = Aluno::where('numero_matricula', $numeroMatricula)->first();

        if ($aluno === null) {
            return new SituacaoAcademicaDoAluno();
        }

        $matricula = Matricula::with(['turma.curso', 'turma.anoLectivo'])
            ->where('aluno_id', $aluno->id)
            ->where('estado', EstadoMatriculaEnum::ACTIVA->value)
            ->orderByDesc('data_matricula')
            ->orderByDesc('id')
            ->first();
        $turma = $matricula?->turma;

        $enquadramento = $aluno->enquadramentosAcademicos()
            ->with('curso')
            ->where('estado', EstadoEnquadramentoAcademicoEnum::ACTIVO->value)
            ->whereNotNull('curso_id')
            ->orderByDesc('data_inicio')
            ->orderByDesc('id')
            ->first();

        return new SituacaoAcademicaDoAluno(
            curso: $enquadramento?->curso?->nome ?? $turma?->curso?->nome,
            turma: $turma === null ? null : trim(($turma->nome ?? $turma->codigo) . ($turma->anoLectivo ? " ({$turma->anoLectivo->nome})" : '')),
        );
    }
}
