<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SessaoTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
    }

    public function test_o_login_grava_o_tenant_na_sessao(): void
    {
        $this->utilizador('a@example.com');

        $this->post($this->urlDoTenant($this->tenant, '/login'), ['login' => 'a@example.com', 'password' => 'segredo123']);

        $this->assertSame($this->tenant->id, session('tenant_id'));
    }

    public function test_login_no_proprio_tenant_continua_a_autenticar(): void
    {
        $this->utilizador('a@example.com');

        $this->post($this->urlDoTenant($this->tenant, '/login'), ['login' => 'a@example.com', 'password' => 'segredo123'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_credenciais_do_tenant_a_nao_entram_no_dominio_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->utilizador('a@example.com');

        $this->post($this->urlDoTenant($outro, '/login'), ['login' => 'a@example.com', 'password' => 'segredo123'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_o_mesmo_email_entra_em_cada_tenant_com_a_sua_palavra_passe(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->utilizador('igual@example.com');
        $this->noTenant($outro, fn () => User::create(['name' => 'B', 'email' => 'igual@example.com', 'password' => Hash::make('outra-senha')]));

        $this->post($this->urlDoTenant($outro, '/login'), ['login' => 'igual@example.com', 'password' => 'outra-senha'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_sessao_de_a_reenviada_ao_dominio_de_b_nao_autentica(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $user = $this->utilizador('a@example.com');

        // actingAs() poria o utilizador directamente no guard, sem passar pelo provider;
        // uma sessão real só guarda o id, e é o provider do tenant B que o tem de recusar.
        $chaveDaSessao = 'login_web_'.sha1(SessionGuard::class);

        $this->withSession([$chaveDaSessao => $user->id])
            ->get($this->urlDoTenant($outro, '/estabelecimento'))
            ->assertRedirect(route('login'));

        // Depois do pedido o contexto volta ao tenant de teste (A); reavalia-se o guard no B.
        $this->noTenant($outro, fn () => $this->assertGuest());
    }

    public function test_sessao_com_tenant_diferente_do_do_pedido_e_invalidada(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $user = $this->utilizador('a@example.com');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $outro->id])
            ->get($this->urlDoTenant($this->tenant, '/estabelecimento'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
