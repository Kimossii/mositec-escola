<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Cenário único, ponta a ponta, com dois tenants e o MESMO email em ambos.
 */
class RedefinicaoSenhaIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'igual@example.com';

    private Tenant $b;

    private User $adminA;

    private User $alvoA;

    private User $alvoB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->b = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->seed(PermissaoDatabaseSeeder::class);

        $this->adminA = User::create(['name' => 'Admin A', 'email' => 'admin-a@example.com', 'password' => Hash::make('segredo123')]);
        $this->adminA->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);
        $this->alvoA = User::create(['name' => 'Alvo A', 'email' => self::EMAIL, 'password' => Hash::make('senha-a-antiga')]);

        $this->alvoB = $this->noTenant($this->b, function () {
            $user = User::create(['name' => 'Alvo B', 'email' => self::EMAIL, 'password' => Hash::make('senha-b-antiga')]);
            $user->createToken('api');
            SessaoDeUtilizador::forceCreate(['id' => 'sessao-b', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);

            return $user;
        });
    }

    private function estadoDeB(): array
    {
        return $this->noTenant($this->b, function () {
            $u = User::findOrFail($this->alvoB->id);

            return [
                $u->password,
                $u->deve_alterar_senha,
                $u->senha_redefinida_por,
                $u->senha_redefinida_em,
                $u->tokens()->count(),
                SessaoDeUtilizador::where('user_id', $u->id)->count(),
                $u->remember_token,
            ];
        });
    }

    private function redefinirEmA(): string
    {
        $this->actingAs($this->adminA)
            ->patch($this->urlDoTenant($this->tenant, "/usuarios/{$this->alvoA->id}/redefinir-senha"))
            ->assertRedirect();

        $flash = session('senha_temporaria');
        $this->assertIsArray($flash);

        return Crypt::decryptString($flash['senha']);
    }

    private function sairDeTudo(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    public function test_redefinir_em_a_nao_altera_o_homonimo_em_b(): void
    {
        $antes = $this->estadoDeB();

        $this->redefinirEmA();

        $this->assertTrue($this->alvoA->fresh()->deve_alterar_senha);
        $this->assertSame($antes, $this->estadoDeB());
        $this->assertSame(1, $antes[4]);
        $this->assertSame(1, $antes[5]);
    }

    public function test_admin_de_a_recebe_404_no_utilizador_de_b_e_nada_muda(): void
    {
        $antes = $this->estadoDeB();

        $this->actingAs($this->adminA)
            ->patch($this->urlDoTenant($this->tenant, "/usuarios/{$this->alvoB->id}/redefinir-senha"))
            ->assertNotFound();

        $this->assertSame($antes, $this->estadoDeB());
    }

    public function test_a_entra_com_a_temporaria_e_e_forcado_a_trocar_e_b_entra_com_a_antiga(): void
    {
        $temporaria = $this->redefinirEmA();
        $this->sairDeTudo();

        $this->post($this->urlDoTenant($this->tenant, '/login'), ['login' => self::EMAIL, 'password' => $temporaria])
            ->assertRedirect();
        $this->assertAuthenticatedAs($this->alvoA);

        $this->get($this->urlDoTenant($this->tenant, '/'))->assertRedirect(route('senha.alterar'));

        $this->put($this->urlDoTenant($this->tenant, '/alterar-senha'), [
            'current_password' => $temporaria,
            'password' => 'nova-senha-segura-456',
            'password_confirmation' => 'nova-senha-segura-456',
        ])->assertRedirect();

        $this->assertFalse($this->alvoA->fresh()->deve_alterar_senha);
        $this->get($this->urlDoTenant($this->tenant, '/'))->assertOk();

        $this->sairDeTudo();

        // B: a senha antiga continua válida, sem troca obrigatória.
        $this->post($this->urlDoTenant($this->b, '/login'), ['login' => self::EMAIL, 'password' => 'senha-b-antiga'])
            ->assertRedirect();
        $this->assertSame($this->alvoB->id, Auth::id());
        $this->get($this->urlDoTenant($this->b, '/'))->assertOk();
    }

    public function test_a_senha_temporaria_de_a_nao_entra_em_b(): void
    {
        $temporaria = $this->redefinirEmA();
        $this->sairDeTudo();

        $this->post($this->urlDoTenant($this->b, '/login'), ['login' => self::EMAIL, 'password' => $temporaria])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_senha_redefinida_por_aponta_para_o_admin_de_a(): void
    {
        $this->redefinirEmA();

        $alvo = $this->alvoA->fresh();
        $this->assertSame($this->adminA->id, $alvo->senha_redefinida_por);
        $this->assertNotNull($alvo->senha_redefinida_em);

        $this->noTenant($this->b, function () {
            $b = User::findOrFail($this->alvoB->id);
            $this->assertNull($b->senha_redefinida_por);
            $this->assertNull($b->senha_redefinida_em);
        });
        $this->assertSame(0, User::withoutGlobalScopes()->where('tenant_id', $this->b->id)->where('senha_redefinida_por', '!=', null)->count());
    }

    public function test_recuperacao_e_registo_dao_404_nos_dois_hosts(): void
    {
        foreach ([$this->tenant, $this->b] as $tenant) {
            $this->get($this->urlDoTenant($tenant, '/forgot-password'))->assertNotFound();
            $this->get($this->urlDoTenant($tenant, '/reset-password/x'))->assertNotFound();
            $this->post($this->urlDoTenant($tenant, '/reset-password'), [
                'token' => 'x', 'email' => self::EMAIL, 'password' => 'segredo1234', 'password_confirmation' => 'segredo1234',
            ])->assertNotFound();
            $this->get($this->urlDoTenant($tenant, '/register'))->assertNotFound();
            $this->post($this->urlDoTenant($tenant, '/register'), [
                'name' => 'X', 'email' => 'x@example.com', 'password' => 'segredo1234', 'password_confirmation' => 'segredo1234',
            ])->assertNotFound();
        }
    }

    public function test_falhas_de_login_em_a_nao_bloqueiam_o_login_em_b(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->urlDoTenant($this->tenant, '/login'), ['login' => self::EMAIL, 'password' => 'errada']);
        }

        // Controlo positivo: A está mesmo bloqueado, mesmo com a senha certa.
        $this->post($this->urlDoTenant($this->tenant, '/login'), ['login' => self::EMAIL, 'password' => 'senha-a-antiga'])
            ->assertSessionHasErrors('login');
        $this->assertStringContainsString('Muitas tentativas', session('errors')->first('login'));
        $this->assertGuest();

        $this->post($this->urlDoTenant($this->b, '/login'), ['login' => self::EMAIL, 'password' => 'senha-b-antiga'])
            ->assertRedirect();
        $this->assertSame($this->alvoB->id, Auth::id());
    }
}
