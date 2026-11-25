<?php

namespace Modules\Portal\Tests\Support;

use Illuminate\Support\Facades\Hash;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;

/** Cenário de famílias: encarregados, educandos e a ligação entre eles. Requer `Tests\TestCase` com `RefreshDatabase`. */
trait CenarioDeFamilia
{
    protected function prepararCenario(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    protected function criarEncarregado(string $email = 'enc@example.com'): User
    {
        return $this->criarComPerfil(User::create(['name' => 'Encarregado ' . $email, 'email' => $email, 'password' => Hash::make('x')]), Perfil::ENCARREGADO);
    }

    protected function criarEducando(string $nome, string $matricula, int $estado = 1): User
    {
        return $this->criarComPerfil(
            User::create(['name' => $nome, 'numero_matricula' => $matricula, 'password' => Hash::make('x'), 'estado' => $estado]),
            Perfil::ALUNO,
        );
    }

    protected function criarComPerfil(User $user, Perfil $perfil): User
    {
        $user->roles()->attach(Role::where('nome', $perfil->value)->value('id'));

        return $user;
    }

    protected function ligar(User $encarregado, User $aluno, ?string $parentesco = null): void
    {
        $encarregado->educandos()->attach($aluno->id, ['parentesco' => $parentesco]);
    }
}
