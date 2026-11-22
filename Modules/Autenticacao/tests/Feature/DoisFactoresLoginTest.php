<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Autenticacao\Tests\Support\ComDoisFactores;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DoisFactoresLoginTest extends TestCase
{
    use RefreshDatabase;
    use ComDoisFactores;

    private function utilizador(bool $comDoisFactores): User
    {
        $user = User::create(['name' => 'Ana Silva', 'email' => 'ana@example.com', 'password' => Hash::make('senha-segura-123')]);

        return $comDoisFactores ? $this->comDoisFactores($user) : $user;
    }

    private function iniciarLogin(): void
    {
        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'senha-segura-123'])->assertRedirect('/two-factor-challenge');
    }

    public function test_login_sem_2fa_continua_igual(): void
    {
        $this->utilizador(false);

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'senha-segura-123'])->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_login_com_2fa_nao_autentica_e_vai_para_o_desafio(): void
    {
        $ana = $this->utilizador(true);

        $this->iniciarLogin();

        $this->assertGuest();
        $this->assertSame($ana->id, session('login.id'));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $ana->id)->count());
    }

    public function test_senha_errada_com_2fa_nao_chega_ao_desafio(): void
    {
        $this->utilizador(true);

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'errada'])->assertSessionHasErrors('login');

        $this->assertNull(session('login.id'));
    }

    public function test_pagina_do_desafio_so_abre_depois_da_senha(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect('/login');

        $this->utilizador(true);
        $this->iniciarLogin();

        $this->get('/two-factor-challenge')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Autenticacao/DesafioDoisFactores'));
    }

    public function test_desafio_com_codigo_valido_autentica_e_define_o_tenant(): void
    {
        $ana = $this->utilizador(true);
        $this->iniciarLogin();

        $this->post('/two-factor-challenge', ['code' => $this->codigoTotp($ana)])->assertRedirect('/');

        $this->assertAuthenticatedAs($ana);
        $this->assertTrue(session()->has('tenant_id'));
    }

    public function test_desafio_com_codigo_errado_falha(): void
    {
        $this->utilizador(true);
        $this->iniciarLogin();

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_codigo_de_recuperacao_so_funciona_uma_vez(): void
    {
        $this->utilizador(true);
        $this->iniciarLogin();

        $this->post('/two-factor-challenge', ['recovery_code' => 'recup-0001'])->assertRedirect('/');
        $this->assertAuthenticated();

        auth()->logout();
        $this->flushSession();
        $this->iniciarLogin();

        $this->post('/two-factor-challenge', ['recovery_code' => 'recup-0001'])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_desafio_e_limitado_apos_5_tentativas(): void
    {
        $this->utilizador(true);
        $this->iniciarLogin();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/two-factor-challenge', ['code' => '000000']);
        }

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertStatus(429);
    }

    public function test_conta_desactivada_entre_passos_nao_entra(): void
    {
        $ana = $this->utilizador(true);
        $this->iniciarLogin();
        $ana->forceFill(['estado' => 0])->save();

        $this->post('/two-factor-challenge', ['code' => $this->codigoTotp($ana)])->assertSessionHasErrors('code');

        $this->assertGuest();
    }
}
