<?php

namespace Modules\Aluno\Licenciamento;

use Modules\Aluno\Models\Aluno;
use Modules\Core\Licenciamento\Contracts\ContaRecursoLicenciado;
use Modules\Core\Enums\Estado;
use Modules\Core\Licenciamento\Enums\Recurso;

/** Alunos ATIVOS do tenant actual (o escopo de tenancy filtra); inativos não gastam vaga. */
class ContaAlunos implements ContaRecursoLicenciado
{
    public function recurso(): Recurso
    {
        return Recurso::ALUNOS;
    }

    public function contar(): int
    {
        return Aluno::query()->where('estado', Estado::ATIVO->value)->count();
    }
}
