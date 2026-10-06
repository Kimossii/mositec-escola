<?php

namespace Modules\Autenticacao\Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Actions\AlterarPropriaSenhaAction;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class TrocaObrigatoriaDeSenhaTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARIA = 'temporaria-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizador(string $email, bool $flag, ?Perfil $perfil = Perfil::ADMIN_ESCOLA): User
    {
        $user = User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make(self::TEMPORARIA)]);
        $user->forceFill(['deve_alterar_senha' => $flag])->save();

        if ($perfil) {
            $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);
        }

        return $user;
    }

    private function dadosValidos(array $extra = []): array
    {
        return array_merge([
            'current_password' => self::TEMPORARIA,
            'password' => 'nova-senha-segura-456',
            'password_confirmation' => 'nova-senha-segura-456',
        ], $extra);
    }

    public function test_login_com_senha_temporaria_leva_a_troca(): void
    {
        $this->utilizador('ana@example.com', true);

        $this->post('/login', ['login' => 'ana@example.com', 'password' => self::TEMPORARIA])->assertRedirect('/');

        $this->get('/')->assertRedirect(route('senha.alterar'));
    }

    public function test_com_a_flag_activa_so_a_troca_e_o_logout_estao_acessiveis(): void
    {
        $user = $this->utilizador('ana@example.com', true);
        $this->actingAs($user);

        $this->get('/')->assertRedirect(route('senha.alterar'));
        $this->get('/usuarios')->assertRedirect(route('senha.alterar'));
        $this->get('/estabelecimento')->assertRedirect(route('senha.alterar'));
        $this->post('/usuarios/alunos/cadastrar', ['name' => 'X', 'email' => 'x@example.com'])
            ->assertRedirect(route('senha.alterar'));
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
        $this->put('/user/profile-information', ['name' => 'Hackeado', 'email' => 'ana@example.com'])
            ->assertRedirect(route('senha.alterar'));
        $this->assertSame('U', $user->fresh()->name);

        $this->get('/alterar-senha')->assertOk();
    }

    public function test_o_logout_funciona_com_a_flag_e_volta_ao_login(): void
    {
        $this->actingAs($this->utilizador('ana@example.com', true));

        $this->get('/alterar-senha')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_o_logout_inertia_funciona_com_a_flag(): void
    {
        $this->actingAs($this->utilizador('ana@example.com', true));

        $resposta = $this->withHeaders(['X-Inertia' => 'true'])->post('/logout');

        $resposta->assertStatus(409)->assertHeader('X-Inertia-Location', '/login');
        $this->assertGuest();
    }

    public function test_pedido_inertia_bloqueado_usa_navegacao_completa(): void
    {
        $this->actingAs($this->utilizador('ana@example.com', true));

        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request())])
            ->get('/usuarios')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('senha.alterar'));
    }

    public function test_visitante_nao_e_afectado_pelo_middleware(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/alterar-senha')->assertRedirect('/login');
    }

    public function test_api_bloqueia_pedidos_enquanto_a_flag_estiver_activa(): void
    {
        $user = $this->utilizador('ana@example.com', true);
        $plain = $user->createToken('api')->plainTextToken;

        $this->withToken($plain)->postJson('/api/v1/autenticacaoApi/api/logout-all-devices')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_api_permite_o_logout_com_a_flag(): void
    {
        $user = $this->utilizador('ana@example.com', true);
        $plain = $user->createToken('api')->plainTextToken;

        $this->withToken($plain)->postJson('/api/v1/autenticacaoApi/api/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_api_funciona_normalmente_sem_flag(): void
    {
        $user = $this->utilizador('ana@example.com', false);
        $plain = $user->createToken('api')->plainTextToken;

        $this->withToken($plain)->postJson('/api/v1/autenticacaoApi/api/logout-all-devices')->assertOk();
    }

    public function test_troca_limpa_a_flag_e_devolve_o_acesso(): void
    {
        $user = $this->utilizador('ana@example.com', true);
        $hashAntigo = $user->password;
        $this->actingAs($user);

        $this->put('/alterar-senha', $this->dadosValidos())->assertRedirect('/');

        $user->refresh();
        $this->assertFalse($user->deve_alterar_senha);
        $this->assertNotSame($hashAntigo, $user->password);
        $this->assertTrue(Hash::check('nova-senha-segura-456', $user->password));

        $this->get('/')->assertOk();
    }

    public function test_troca_termina_com_navegacao_completa_num_pedido_inertia(): void
    {
        $this->actingAs($this->utilizador('ana@example.com', true));

        $this->withHeaders(['X-Inertia' => 'true'])->put('/alterar-senha', $this->dadosValidos())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', '/');
    }

    public function test_validacoes_da_troca(): void
    {
        $user = $this->utilizador('ana@example.com', true);
        $hash = $user->password;
        $this->actingAs($user);

        $casos = [
            'current_password' => ['current_password' => 'errada-123456'],
            'password' => ['password_confirmation' => 'diferente-123456'],
        ];

        foreach ($casos as $campo => $extra) {
            $this->put('/alterar-senha', $this->dadosValidos($extra))->assertSessionHasErrors($campo);
        }

        $this->put('/alterar-senha', $this->dadosValidos(['password' => 'curta', 'password_confirmation' => 'curta']))
            ->assertSessionHasErrors('password');

        $this->put('/alterar-senha', $this->dadosValidos([
            'password' => self::TEMPORARIA,
            'password_confirmation' => self::TEMPORARIA,
        ]))->assertSessionHasErrors('password');

        $user->refresh();
        $this->assertTrue($user->deve_alterar_senha);
        $this->assertSame($hash, $user->password);
    }

    public function test_troca_roda_o_remember_token_e_mantem_a_sessao_actual(): void
    {
        $user = $this->utilizador('ana@example.com', true);
        $user->forceFill(['remember_token' => 'antigo-token'])->save();
        $this->actingAs($user);

        $this->put('/alterar-senha', $this->dadosValidos())->assertRedirect('/');

        $this->assertNotSame('antigo-token', $user->fresh()->remember_token);
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk();
    }

    public function test_administrador_inicial_sem_flag_nao_e_afectado(): void
    {
        $this->actingAs($this->utilizador('admin@example.com', false));

        $this->get('/')->assertOk();
        $this->get('/usuarios')->assertOk();
    }

    public function test_utilizador_sem_flag_pode_aceder_a_troca_voluntaria(): void
    {
        $user = $this->utilizador('ana@example.com', false);
        $this->actingAs($user);

        $this->get('/alterar-senha')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Autenticacao/AlterarSenha'));
        $this->get('/alterar-senha')->assertViewIs('layouts.guest');

        $this->put('/alterar-senha', $this->dadosValidos())->assertRedirect('/');
        $this->assertTrue(Hash::check('nova-senha-segura-456', $user->fresh()->password));
    }

    public function test_o_tenant_da_flag_e_o_do_utilizador(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $emA = $this->utilizador('ana@example.com', true);
        $emB = $this->noTenant($outro, fn () => $this->utilizador('ana@example.com', true, null));

        $this->actingAs($emA)->put('/alterar-senha', $this->dadosValidos())->assertRedirect('/');
        $this->assertFalse($emA->fresh()->deve_alterar_senha);

        Auth::logout();
        $this->actingAs($emB)->get($this->urlDoTenant($outro, '/'))->assertRedirectContains('/alterar-senha');
        $this->noTenant($outro, function () use ($emB) {
            $this->assertTrue(User::findOrFail($emB->id)->deve_alterar_senha);
            $this->assertTrue(Hash::check(self::TEMPORARIA, User::findOrFail($emB->id)->password));
        });
    }

    public function test_a_troca_obrigatoria_tem_prioridade_sobre_a_configuracao_inicial(): void
    {
        Estabelecimento::current()->forceFill(['configurado_em' => null])->save();
        $this->actingAs($this->utilizador('admin@example.com', true));

        $this->get('/')->assertRedirect(route('senha.alterar'));
        $this->get('/usuarios')->assertRedirect(route('senha.alterar'));
    }

    private function sessao(string $id, User $user): void
    {
        SessaoDeUtilizador::forceCreate(['id' => $id, 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    }

    public function test_a_troca_invalida_os_outros_dispositivos_e_mantem_a_sessao_actual(): void
    {
        $user = $this->utilizador('ana@example.com', true);
        $outro = $this->utilizador('bia@example.com', false);
        $user->createToken('api');
        $outro->createToken('api');
        $actual = str_repeat('a', 40);
        $this->sessao($actual, $user);
        $this->sessao('sessao-outro-dispositivo', $user);
        $this->sessao('sessao-da-bia', $outro);

        app(AlterarPropriaSenhaAction::class)->executar($user, 'nova-senha-segura-456', $actual);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame([$actual], SessaoDeUtilizador::where('user_id', $user->id)->pluck('id')->all());
        $this->assertSame(1, $outro->tokens()->count());
        $this->assertSame(1, SessaoDeUtilizador::where('user_id', $outro->id)->count());
    }

    public function test_o_controller_passa_o_id_da_sessao_actual_a_action(): void
    {
        $recebido = null;
        $this->mock(AlterarPropriaSenhaAction::class, function ($mock) use (&$recebido) {
            $mock->shouldReceive('executar')->once()->andReturnUsing(function ($user, $nova, $idSessao) use (&$recebido) {
                $recebido = $idSessao;
            });
        });

        $this->actingAs($this->utilizador('ana@example.com', true))
            ->put('/alterar-senha', $this->dadosValidos())->assertRedirect('/');

        $this->assertSame(session()->getId(), $recebido);
    }

    public function test_a_troca_nao_toca_nas_sessoes_e_tokens_do_homonimo_noutro_tenant(): void
    {
        $outroTenant = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $emA = $this->utilizador('ana@example.com', true);
        $emB = $this->noTenant($outroTenant, function () {
            $user = $this->utilizador('ana@example.com', false, null);
            $user->createToken('api');

            return $user;
        });
        $this->sessao('sessao-b', $emB);
        $this->sessao('sessao-a', $emA);
        $emA->createToken('api');

        $this->actingAs($emA)->put('/alterar-senha', $this->dadosValidos())->assertRedirect('/');

        $this->assertSame(0, $emA->tokens()->count());
        $this->assertSame(0, SessaoDeUtilizador::where('user_id', $emA->id)->count());
        $this->assertSame(1, SessaoDeUtilizador::where('id', 'sessao-b')->count());
        $this->noTenant($outroTenant, fn () => $this->assertSame(1, User::findOrFail($emB->id)->tokens()->count()));
    }

    public function test_rotas_fortify_user_ficam_bloqueadas_com_a_flag(): void
    {
        $this->actingAs($this->utilizador('ana@example.com', true));

        $this->post('/user/confirm-password', ['password' => self::TEMPORARIA])->assertRedirect(route('senha.alterar'));
        $this->post('/user/two-factor-authentication')->assertRedirect(route('senha.alterar'));
        $this->assertNull(Auth::user()->fresh()->two_factor_secret);
    }

    public function test_rota_de_negocio_da_api_e_bloqueada_com_403_json(): void
    {
        $plain = $this->utilizador('ana@example.com', true)->createToken('api')->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/turmas')->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_bearer_token_numa_rota_web_e_bloqueado(): void
    {
        $plain = $this->utilizador('ana@example.com', true)->createToken('api')->plainTextToken;

        $this->withToken($plain)->getJson('/usuarios')->assertUnauthorized();
    }

    public function test_com_flag_e_escola_por_configurar_nao_ha_ciclo_de_redireccionamentos(): void
    {
        Estabelecimento::current()->forceFill(['configurado_em' => null])->save();
        $user = $this->utilizador('admin@example.com', true);
        $this->actingAs($user);

        $this->get('/alterar-senha')->assertOk();
        $this->get('/')->assertRedirect(route('senha.alterar'));

        $this->put('/alterar-senha', $this->dadosValidos())->assertRedirect('/');
        $this->assertFalse($user->fresh()->deve_alterar_senha);

        $this->get('/')->assertRedirect(route('estabelecimento.dados'));
        $this->get('/estabelecimento')->assertOk();
    }
}
