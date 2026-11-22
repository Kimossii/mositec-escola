<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Tests\Support\ComDoisFactores;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ContaDesactivadaLoginTest extends TestCase
{
    use ComDoisFactores;
    use RefreshDatabase;

    private function utilizador(int $estado): User
    {
        $user = User::create(['name' => 'Ana Silva', 'email' => 'ana@example.com', 'password' => Hash::make('senha-segura-123')]);
        $user->forceFill(['estado' => $estado])->save();

        return $user->fresh();
    }

    public function test_conta_desactivada_nao_entra_na_web(): void
    {
        $this->utilizador(0);

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'senha-segura-123'])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_conta_desactivada_nao_recebe_token_na_api(): void
    {
        $ana = $this->utilizador(0);

        $this->postJson('/api/v1/autenticacaoApi/api/login', ['email' => 'ana@example.com', 'password' => 'senha-segura-123'])
            ->assertStatus(403)->assertJsonMissingPath('token');

        $this->assertSame(0, $ana->tokens()->count());
    }

    public function test_conta_desactivada_com_2fa_nem_chega_ao_desafio(): void
    {
        $this->comDoisFactores($this->utilizador(0));

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'senha-segura-123'])->assertSessionHasErrors('login');
        $this->assertNull(session('login.id'));

        $this->postJson('/api/v1/autenticacaoApi/api/login', ['email' => 'ana@example.com', 'password' => 'senha-segura-123'])
            ->assertStatus(403)->assertJsonMissingPath('desafio');
    }

    public function test_senha_errada_numa_conta_desactivada_nao_revela_que_esta_desactivada(): void
    {
        $this->utilizador(0);

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'errada'])
            ->assertSessionHasErrors(['login' => 'Credenciais inválidas']);
    }

    public function test_conta_activada_continua_a_entrar(): void
    {
        $this->utilizador(1);

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'senha-segura-123'])->assertRedirect('/');

        $this->assertAuthenticated();
    }
}
