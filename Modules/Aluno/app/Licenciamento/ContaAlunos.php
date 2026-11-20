<?php

namespace Modules\Aluno\Licenciamento;

use Modules\Aluno\Models\Aluno;
use Modules\Core\Licenciamento\Contracts\ContaRecursoLicenciado;
use Modules\Core\Licenciamento\Enums\Recurso;

/** Alunos do tenant actual (o escopo de tenancy do modelo filtra). */
class ContaAlunos implements ContaRecursoLicenciado
{
    public function recurso(): Recurso
    {
        return Recurso::ALUNOS;
    }

    public function contar(): int
    {
        return Aluno::query()->count();
    }
}
