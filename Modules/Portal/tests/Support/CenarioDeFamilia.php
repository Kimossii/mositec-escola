<?php

namespace Modules\Portal\Tests\Support;

use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\DadosPessoa;
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

    /** Um aluno (ficha), sem conta de login: o encarregado liga-se à ficha. */
    protected function criarEducando(string $nome, string $matricula, int $estado = 1): Aluno
    {
        $pessoa = DadosPessoa::create(['nome_completo' => $nome, 'numero_identificacao' => 'BI-' . $matricula, 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        return Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => $matricula,
            'estado' => $estado,
        ]);
    }

    protected function criarComPerfil(User $user, Perfil $perfil): User
    {
        $user->roles()->attach(Role::where('nome', $perfil->value)->value('id'));

        return $user;
    }

    protected function ligar(User $encarregado, Aluno $aluno, ?string $parentesco = null): void
    {
        $encarregado->educandos()->attach($aluno->dados_pessoa_id, ['parentesco' => $parentesco]);
    }
}
