<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * `auth.user` vai em toda página Inertia — o único consumidor no frontend
 * (UserMenu.vue) só lê `name` e `email`. Antes disto, o `$request->user()`
 * completo ia inteiro (hash da password e remember_token ocultos pelo
 * `$hidden` do Model, mas tudo o resto exposto sem necessidade).
 */
class AuthUserCompartilhadoInertiaTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_user_so_traz_id_name_e_email(): void
    {
        $user = User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
            'numero_matricula' => null,
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('auth.user', fn (Assert $auth) => $auth
                ->where('id', $user->id)
                ->where('name', 'Ana Silva')
                ->where('email', 'ana@example.com')
                ->etc()
            )
        );

        $props = $response->viewData('page')['props'];

        $this->assertSame(['id', 'name', 'email'], array_keys($props['auth']['user']));
    }

    public function test_utilizador_sem_perfil_de_staff_nao_expoe_dados_alem_dos_tres_campos(): void
    {
        $aluno = User::create([
            'name' => 'Aluno Teste',
            'numero_matricula' => '2026-0001',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($aluno)->get('/');
        $props = $response->viewData('page')['props'];

        $this->assertSame(['id', 'name', 'email'], array_keys($props['auth']['user']));
        $this->assertNull($props['auth']['user']['email']);
    }
}
