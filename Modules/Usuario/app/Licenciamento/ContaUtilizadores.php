<?php

namespace Modules\Usuario\Licenciamento;

use Modules\Core\Enums\Estado;
use Modules\Core\Licenciamento\Contracts\ContaRecursoLicenciado;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;

/** Utilizadores ATIVOS do tenant actual, sem ser APENAS conta de aluno (limite próprio) e sem Administrador da escola. */
class ContaUtilizadores implements ContaRecursoLicenciado
{
    public function recurso(): Recurso
    {
        return Recurso::UTILIZADORES;
    }

    public function contar(): int
    {
        return User::query()
            ->where('estado', Estado::ATIVO->value)
            ->whereDoesntHave('roles', fn ($roles) => $roles->where('nome', Perfil::ADMIN_ESCOLA->value))
            // Conta quem tem ao menos um perfil que não é Aluno (ou nenhum): um Aluno com perfil de staff acumulado continua a contar.
            // O grupo aninhado mantém o escopo de tenancy a valer nos dois ramos do OR.
            ->where(fn ($q) => $q
                ->whereHas('roles', fn ($roles) => $roles->where('nome', '!=', Perfil::ALUNO->value))
                ->orWhereDoesntHave('roles'))
            ->count();
    }
}
