<?php

namespace Modules\Aluno\Services;

use Modules\Aluno\Models\Aluno;
use Modules\Core\Contracts\ProcuraFotosDeAlunos;

/** Implementação, no módulo Aluno, do contrato de fotos usado pela lista de contas do Usuario. */
class ProcuraFotosDeAlunosService implements ProcuraFotosDeAlunos
{
    public function urlsPorMatricula(array $numerosMatricula): array
    {
        if ($numerosMatricula === []) {
            return [];
        }

        return Aluno::whereIn('numero_matricula', $numerosMatricula)
            ->whereNotNull('foto_path')
            ->get()
            ->mapWithKeys(fn (Aluno $aluno) => [$aluno->numero_matricula => $aluno->foto_url])
            ->filter()
            ->all();
    }
}
