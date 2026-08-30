<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Services\PermissaoConsultaService;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissaoConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissoes_herdadas_e_a_uniao_de_todos_os_perfis_do_utilizador(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof3@example.com', 'password' => Hash::make('x')]);
        $roleProfessor = Role::create(['nome' => 2, 'descricao' => 'Professor', 'estado' => 1]);
        $roleSecretario = Role::create(['nome' => 1, 'descricao' => 'Secretário', 'estado' => 1]);
        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acaoVer = Acao::create(['nome' => 'ver', 'numero' => 0, 'estado' => 1]);
        $acaoCriar = Acao::create(['nome' => 'criar', 'numero' => 1, 'estado' => 1]);

        RolePermissao::create(['role_id' => $roleProfessor->id, 'modulo_id' => $modulo->id, 'acao_id' => $acaoVer->id]);
        RolePermissao::create(['role_id' => $roleSecretario->id, 'modulo_id' => $modulo->id, 'acao_id' => $acaoCriar->id]);

        $user->roles()->attach([$roleProfessor->id, $roleSecretario->id]);

        $herdadas = (new PermissaoConsultaService())->permissoesHerdadas($user);

        $this->assertCount(2, $herdadas);
    }
}
