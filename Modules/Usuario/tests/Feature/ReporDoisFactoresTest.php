<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Actions\RecuperarAdministradorAction;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Tests\Support\ComDoisFactores;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ReporDoisFactoresTest extends TestCase
{
    use RefreshDatabase;
    use ComDoisFactores;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizador(string $email, ?Perfil $perfil = null, bool $comDoisFactores = false): User
    {
        $user = User::create(['name' => 'U '.$email, 'email' => $email, 'password' => Hash::make('senha-antiga-123')]);

        if ($perfil) {
            $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);
        }

        return $comDoisFactores ? $this->comDoisFactores($user) : $user;
    }

    public function test_admin_repoe_o_2fa_de_outro_utilizador_e_termina_o_acesso_dele(): void
    {
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $alvo = $this->utilizador('alvo@example.com', Perfil::PROFESSOR, true);
        $alvo->createToken('api');
        SessaoDeUtilizador::forceCreate(['id' => 'sessao-alvo', 'user_id' => $alvo->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-2fa")->assertRedirect();

        $alvo->refresh();
        $this->assertNull($alvo->two_factor_secret);
        $this->assertNull($alvo->two_factor_recovery_codes);
        $this->assertNull($alvo->two_factor_confirmed_at);
        $this->assertFalse($alvo->hasEnabledTwoFactorAuthentication());
        $this->assertSame(0, $alvo->tokens()->count());
        $this->assertSame(0, SessaoDeUtilizador::where('user_id', $alvo->id)->count());
    }

    public function test_ninguem_repoe_o_proprio_2fa(): void
    {
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA, true);

        $this->actingAs($admin)->patch("/usuarios/{$admin->id}/redefinir-2fa")->assertForbidden();

        $this->assertTrue($admin->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_sem_permissao_da_403(): void
    {
        $funcionario = $this->utilizador('func@example.com', Perfil::FUNCIONARIO);
        $alvo = $this->utilizador('alvo@example.com', null, true);

        $this->actingAs($funcionario)->patch("/usuarios/{$alvo->id}/redefinir-2fa")->assertForbidden();

        $this->assertTrue($alvo->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_utilizador_de_outro_tenant_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $emB = $this->noTenant($outro, fn () => $this->comDoisFactores(User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('x')])));

        $this->actingAs($admin)->patch($this->urlDoTenant($this->tenant, "/usuarios/{$emB->id}/redefinir-2fa"))->assertNotFound();

        $this->noTenant($outro, fn () => $this->assertTrue(User::findOrFail($emB->id)->hasEnabledTwoFactorAuthentication()));
    }

    public function test_so_admin_repoe_o_2fa_de_uma_conta_de_admin(): void
    {
        $outroAdmin = $this->utilizador('admin2@example.com', Perfil::ADMIN_ESCOLA, true);
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $professor = $this->utilizador('prof@example.com', Perfil::PROFESSOR);

        // Sem senha-utilizador.editar ninguém repõe o 2FA de um administrador; o administrador consegue.
        $this->actingAs($professor)->patch("/usuarios/{$outroAdmin->id}/redefinir-2fa")->assertForbidden();
        $this->assertTrue($outroAdmin->fresh()->hasEnabledTwoFactorAuthentication());

        $this->actingAs($admin)->patch("/usuarios/{$outroAdmin->id}/redefinir-2fa")->assertRedirect();
        $this->assertFalse($outroAdmin->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_a_lista_de_utilizadores_indica_2fa_sem_expor_o_segredo(): void
    {
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $this->utilizador('alvo@example.com', Perfil::PROFESSOR, true);

        $resposta = $this->actingAs($admin)->get('/usuarios/professores');

        $resposta->assertOk();
        $this->assertStringNotContainsString('two_factor_secret', $resposta->getContent());
        $this->assertStringNotContainsString('two_factor_recovery_codes', $resposta->getContent());
        $this->assertStringContainsString('tem_dois_factores', $resposta->getContent());
    }

    public function test_recuperar_administrador_limpa_o_2fa(): void
    {
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA, true);
        $admin->createToken('api');

        app(RecuperarAdministradorAction::class)->executar('admin@example.com');

        $admin->refresh();
        $this->assertFalse($admin->hasEnabledTwoFactorAuthentication());
        $this->assertNull($admin->two_factor_secret);
    }

    public function test_repor_o_2fa_invalida_o_cookie_lembrar_me(): void
    {
        $admin = $this->utilizador('admin@example.com', Perfil::ADMIN_ESCOLA);
        $alvo = $this->utilizador('alvo@example.com', Perfil::PROFESSOR, true);
        $alvo->forceFill(['remember_token' => 'token-antigo-do-cookie'])->save();

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-2fa")->assertRedirect();

        $this->assertNotSame('token-antigo-do-cookie', $alvo->fresh()->remember_token);
    }
}
