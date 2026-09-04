<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SincronizarPermissoesUtilizadorActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sincroniza_overrides_do_utilizador_substituindo_o_estado_anterior(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x')]);
        $modulo1 = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $modulo2 = Modulo::create(['nome' => 1, 'descricao' => 'Aluno', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'eliminar', 'numero' => 3, 'estado' => 1]);

        UserPermissao::create(['users_id' => $user->id, 'modulo_id' => $modulo1->id, 'acao_id' => $acao->id, 'permitido' => true]);

        app(SincronizarPermissoesUtilizadorAction::class)->executar($user, [
            ['modulo_id' => $modulo2->id, 'acao_id' => $acao->id, 'permitido' => false],
        ]);

        $this->assertDatabaseMissing('user_permissoes', ['users_id' => $user->id, 'modulo_id' => $modulo1->id]);
        $this->assertDatabaseHas('user_permissoes', ['users_id' => $user->id, 'modulo_id' => $modulo2->id, 'acao_id' => $acao->id, 'permitido' => false]);
    }

    public function test_lista_vazia_remove_todos_os_overrides_do_utilizador(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof2@example.com', 'password' => Hash::make('x')]);
        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'ver', 'numero' => 0, 'estado' => 1]);

        UserPermissao::create(['users_id' => $user->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true]);

        app(SincronizarPermissoesUtilizadorAction::class)->executar($user, []);

        $this->assertSame(0, UserPermissao::where('users_id', $user->id)->count());
    }
}
