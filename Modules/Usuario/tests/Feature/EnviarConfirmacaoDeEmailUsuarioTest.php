<?php

namespace Modules\Usuario\Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Autenticacao\Notifications\ConfirmarEmailNotificacao;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EnviarConfirmacaoDeEmailUsuarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.exemplo.ao', 'mail.from.address' => 'noreply@plataforma.test']);
    }

    private function utilizador(string $email, Perfil $perfil, array $extra = []): User
    {
        $user = User::create(['name' => 'U '.$email, 'email' => $email, 'password' => Hash::make('x'), ...$extra]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);

        return $user;
    }

    public function test_o_administrador_envia_a_confirmacao_a_um_encarregado(): void
    {
        Notification::fake();
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $pai = $this->utilizador('pai@example.com', Perfil::ENCARREGADO);

        $this->actingAs($admin)->post("/usuarios/{$pai->id}/confirmar-email")->assertSessionHasNoErrors();

        Notification::assertSentTo($pai, ConfirmarEmailNotificacao::class);
        Notification::assertNotSentTo($admin, ConfirmarEmailNotificacao::class);
    }

    public function test_quem_nao_tem_a_permissao_de_senhas_e_recusado(): void
    {
        Notification::fake();
        $professor = $this->utilizador('prof@example.com', Perfil::PROFESSOR);
        $pai = $this->utilizador('pai@example.com', Perfil::ENCARREGADO);

        $this->actingAs($professor)->post("/usuarios/{$pai->id}/confirmar-email")->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_o_administrador_nao_a_usa_na_propria_conta(): void
    {
        Notification::fake();
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);

        $this->actingAs($admin)->post("/usuarios/{$admin->id}/confirmar-email")->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_sem_canal_de_email_ou_com_conta_ja_confirmada_ou_por_matricula_avisa_e_nao_envia(): void
    {
        Notification::fake();
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $confirmado = $this->utilizador('ok@example.com', Perfil::ENCARREGADO);
        $confirmado->forceFill(['email_verified_at' => now()])->save();
        $matricula = $this->utilizador('m@example.com', Perfil::ALUNO, ['tipo_login' => TipoLogin::MATRICULA]);
        $pai = $this->utilizador('pai@example.com', Perfil::ENCARREGADO);

        $this->actingAs($admin)->post("/usuarios/{$confirmado->id}/confirmar-email")->assertSessionHasErrors('email');
        $this->actingAs($admin)->post("/usuarios/{$matricula->id}/confirmar-email")->assertSessionHasErrors('email');

        config(['mail.default' => 'log']);
        $this->actingAs($admin)->post("/usuarios/{$pai->id}/confirmar-email")->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_a_lista_sinaliza_quem_tem_o_email_por_confirmar(): void
    {
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $pai = $this->utilizador('pai@example.com', Perfil::ENCARREGADO);
        $confirmado = $this->utilizador('ok@example.com', Perfil::ENCARREGADO);
        $confirmado->forceFill(['email_verified_at' => now()])->save();

        $resposta = $this->actingAs($admin)->get('/usuarios/encarregados', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ])->assertOk();

        // As linhas vêm dentro das props da página; apanha todas as que tenham `email_por_confirmar`, venham de onde vierem.
        $linhas = collect();
        $this->recolherLinhas($resposta->json('props'), $linhas);

        $this->assertTrue($linhas->firstWhere('email', 'pai@example.com')['email_por_confirmar']);
        $this->assertFalse($linhas->firstWhere('email', 'ok@example.com')['email_por_confirmar']);
        $this->assertSame($pai->id, $linhas->firstWhere('email', 'pai@example.com')['id']);
    }

    /** @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $linhas */
    private function recolherLinhas(mixed $no, $linhas): void
    {
        if (! is_array($no)) {
            return;
        }

        if (array_key_exists('email_por_confirmar', $no)) {
            $linhas->push($no);

            return;
        }

        foreach ($no as $filho) {
            $this->recolherLinhas($filho, $linhas);
        }
    }
}
