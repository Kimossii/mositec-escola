<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RecuperacaoPalavraPasseHttpTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('antiga-123')]);
    }

    /** A aplicação ainda não regista a acção de reposição do Fortify; o teste liga uma mínima. */
    private function ligarAccaoDeReposicao(): void
    {
        $this->app->bind(ResetsUserPasswords::class, fn () => new class implements ResetsUserPasswords
        {
            public function reset($user, array $input): void
            {
                $user->forceFill(['password' => Hash::make($input['password'])])->save();
            }
        });
    }

    public function test_pedir_reposicao_no_dominio_de_a_cria_o_token_so_com_o_tenant_de_a(): void
    {
        Notification::fake();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));

        $this->post($this->urlDoTenant($this->tenant, '/forgot-password'), ['email' => 'igual@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $tokens = DB::table('password_reset_tokens')->where('email', 'igual@example.com')->get();
        $this->assertCount(1, $tokens);
        $this->assertSame($this->tenant->id, (int) $tokens->first()->tenant_id);
        Notification::assertSentTo($userA, ResetPassword::class);
        Notification::assertNotSentTo($userB, ResetPassword::class);
    }

    public function test_o_token_de_a_nao_repoe_a_palavra_passe_de_b_mas_repoe_a_de_a(): void
    {
        $this->ligarAccaoDeReposicao();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));
        $tokenDeA = Password::broker()->createToken($userA);
        $dados = [
            'email' => 'igual@example.com',
            'token' => $tokenDeA,
            'password' => 'nova-senha-123',
            'password_confirmation' => 'nova-senha-123',
        ];

        $this->post($this->urlDoTenant($outro, '/reset-password'), $dados)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('antiga-123', $userB->fresh()->password));
        $this->assertTrue(Hash::check('antiga-123', $userA->fresh()->password));

        $this->post($this->urlDoTenant($this->tenant, '/reset-password'), $dados)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Hash::check('nova-senha-123', $userA->fresh()->password));
        $this->assertTrue(Hash::check('antiga-123', $userB->fresh()->password));
    }
}
