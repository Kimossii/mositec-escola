<?php

namespace Modules\Financeiro\Tests\Concerns;

use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;

trait ComUtilizadoresFinanceiro
{
    protected function adminEscola(): User
    {
        return $this->utilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin-fin@example.com');
    }

    protected function professor(): User
    {
        return $this->utilizadorComPerfil(Perfil::PROFESSOR, 'professor-fin@example.com');
    }

    private function utilizadorComPerfil(Perfil $perfil, string $email): User
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => 'Teste', 'password' => Hash::make('segredo123')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->firstOrFail()->id]);

        return $user;
    }
}
