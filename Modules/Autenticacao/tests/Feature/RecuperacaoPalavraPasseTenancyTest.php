<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RecuperacaoPalavraPasseTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('antiga')]);
    }

    public function test_o_token_fica_gravado_com_o_tenant(): void
    {
        $user = $this->utilizador('igual@example.com');

        Password::broker()->createToken($user);

        $this->assertDatabaseHas('password_reset_tokens', ['tenant_id' => $this->tenant->id, 'email' => 'igual@example.com']);
    }

    public function test_o_mesmo_email_em_dois_tenants_tem_um_token_para_cada(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));

        Password::broker()->createToken($userA);
        $this->noTenant($outro, fn () => Password::broker()->createToken($userB));

        $this->assertDatabaseCount('password_reset_tokens', 2);
    }

    public function test_pedir_reposicao_em_a_nao_apaga_o_token_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));
        $tokenDeB = $this->noTenant($outro, fn () => Password::broker()->createToken($userB));

        Password::broker()->createToken($userA);
        Password::broker()->createToken($userA); // apaga o anterior de A, nunca o de B

        $this->assertSame(
            true,
            $this->noTenant($outro, fn () => Password::broker()->tokenExists($userB, $tokenDeB)),
        );
    }

    public function test_o_token_de_a_nao_repoe_a_palavra_passe_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));
        $tokenDeA = Password::broker()->createToken($userA);

        $estado = $this->noTenant($outro, fn () => Password::broker()->reset(
            ['email' => 'igual@example.com', 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123', 'token' => $tokenDeA],
            function () {},
        ));

        $this->assertSame(Password::INVALID_TOKEN, $estado);
        $this->assertTrue(Hash::check('antiga', $userB->fresh()->password));
    }

    public function test_a_limpeza_de_tokens_expirados_so_toca_no_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));
        Password::broker()->createToken($userA);
        $this->noTenant($outro, fn () => Password::broker()->createToken($userB));

        $this->travel(3)->hours();
        Password::broker()->getRepository()->deleteExpired();
        $this->travelBack();

        $this->assertDatabaseCount('password_reset_tokens', 1);
    }
}
