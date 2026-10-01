<?php

namespace Modules\Usuario\Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Models\UserPermissao;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RedefinirSenhaUsuarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizador(string $email, ?Perfil $perfil = null, string $senha = 'senha-antiga-123'): User
    {
        $user = User::create(['name' => 'U ' . $email, 'email' => $email, 'password' => Hash::make($senha)]);

        if ($perfil) {
            $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);
        }

        return $user;
    }

    private function admin(string $email = 'admin@example.com'): User
    {
        return $this->utilizador($email, Perfil::ADMIN_ESCOLA);
    }

    private function cabecalhosInertia(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ];
    }

    private function senhaDoFlash(): string
    {
        $flash = session('senha_temporaria');
        $this->assertIsArray($flash);

        return Crypt::decryptString($flash['senha']);
    }

    public function test_admin_redefine_a_senha_de_um_utilizador_do_seu_tenant(): void
    {
        $admin = $this->admin();
        $alvo = $this->utilizador('alvo@example.com', Perfil::PROFESSOR);
        $hashAntigo = $alvo->password;

        $resposta = $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-senha");

        $resposta->assertRedirect();
        $alvo->refresh();
        $this->assertTrue($alvo->deve_alterar_senha);
        $this->assertSame($admin->id, $alvo->senha_redefinida_por);
        $this->assertTrue($alvo->senha_redefinida_em->between(Carbon::now()->subMinute(), Carbon::now()->addMinute()));
        $this->assertSame($admin->id, $alvo->editado_por);
        $this->assertNotSame($hashAntigo, $alvo->password);

        $flash = session('senha_temporaria');
        $this->assertSame($alvo->id, $flash['user_id']);
        $this->assertSame($alvo->name, $flash['nome']);
        $claro = $this->senhaDoFlash();
        $this->assertTrue(Hash::check($claro, $alvo->password));
        $this->assertNotSame($claro, $alvo->getRawOriginal('password'));
        $this->assertDatabaseMissing('users', ['password' => $claro]);
    }

    public function test_a_senha_temporaria_e_aleatoria_e_diferente_em_cada_redefinicao(): void
    {
        $admin = $this->admin();
        $alvo = $this->utilizador('alvo@example.com');

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-senha");
        $primeira = $this->senhaDoFlash();
        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-senha");
        $segunda = $this->senhaDoFlash();

        $this->assertNotSame($primeira, $segunda);
        $this->assertGreaterThanOrEqual(14, strlen($primeira));
    }

    public function test_a_senha_temporaria_aparece_uma_so_vez(): void
    {
        $admin = $this->admin();
        $alvo = $this->utilizador('alvo@example.com');

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-senha");
        $senha = $this->senhaDoFlash();

        $primeira = $this->actingAs($admin)->withHeaders($this->cabecalhosInertia())->get('/usuarios');
        $this->assertSame($senha, $primeira->json('props.flash.senha_temporaria.senha'));

        $segunda = $this->actingAs($admin)->withHeaders($this->cabecalhosInertia())->get('/usuarios');
        $segunda->assertOk();
        $this->assertNull($segunda->json('props.flash.senha_temporaria'));
        $this->assertStringNotContainsString($senha, $segunda->getContent());
    }

    public function test_so_a_resposta_com_a_senha_temporaria_cifra_o_historico(): void
    {
        $admin = $this->admin();
        $alvo = $this->utilizador('alvo@example.com');

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-senha");

        $com = $this->actingAs($admin)->withHeaders($this->cabecalhosInertia())->get('/usuarios');
        $this->assertTrue($com->json('encryptHistory'));

        $sem = $this->actingAs($admin)->withHeaders($this->cabecalhosInertia())->get('/usuarios');
        $this->assertNull($sem->json('encryptHistory'));
    }

    public function test_a_senha_temporaria_nao_e_registada_em_logs(): void
    {
        $registos = [];
        Log::listen(function ($evento) use (&$registos) {
            $registos[] = $evento->message . ' ' . json_encode($evento->context);
        });

        $admin = $this->admin();
        $alvo = $this->utilizador('alvo@example.com');

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-senha");
        $senha = $this->senhaDoFlash();

        foreach ($registos as $registo) {
            $this->assertStringNotContainsString($senha, $registo);
        }
    }

    public function test_sessoes_tokens_e_remember_token_sao_invalidados(): void
    {
        $admin = $this->admin();
        $alvo = $this->utilizador('alvo@example.com');
        $outro = $this->utilizador('outro@example.com');
        $alvo->forceFill(['remember_token' => 'token-antigo'])->save();
        $outro->forceFill(['remember_token' => 'token-do-outro'])->save();

        foreach ([[$alvo, 'sessao-alvo'], [$outro, 'sessao-outro']] as [$user, $id]) {
            SessaoDeUtilizador::forceCreate([
                'id' => $id,
                'user_id' => $user->id,
                'payload' => 'x',
                'last_activity' => time(),
            ]);
            $user->createToken('api');
        }

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-senha")->assertRedirect();

        $this->assertSame(0, SessaoDeUtilizador::where('user_id', $alvo->id)->count());
        $this->assertSame(0, $alvo->tokens()->count());
        $this->assertNotSame('token-antigo', $alvo->fresh()->remember_token);

        $this->assertSame(1, SessaoDeUtilizador::where('user_id', $outro->id)->count());
        $this->assertSame(1, $outro->tokens()->count());
        $this->assertSame('token-do-outro', $outro->fresh()->remember_token);
    }

    public function test_senha_antiga_deixa_de_entrar_e_a_temporaria_entra(): void
    {
        $admin = $this->admin();
        $alvo = $this->utilizador('alvo@example.com', null, 'senha-antiga-123');

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-senha");
        $temporaria = $this->senhaDoFlash();
        Auth::logout();

        $this->post('/login', ['login' => 'alvo@example.com', 'password' => 'senha-antiga-123'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->post('/login', ['login' => 'alvo@example.com', 'password' => $temporaria])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($alvo);
    }

    public function test_funciona_para_utilizador_so_com_matricula_sem_email(): void
    {
        $admin = $this->admin();
        $aluno = User::create([
            'name' => 'Aluno',
            'numero_matricula' => '2026-0001',
            'tipo_login' => TipoLogin::MATRICULA,
            'password' => Hash::make('senha-antiga-123'),
        ]);

        $this->actingAs($admin)->patch("/usuarios/{$aluno->id}/redefinir-senha")->assertRedirect();

        $aluno->refresh();
        $this->assertTrue($aluno->deve_alterar_senha);
        $this->assertTrue(Hash::check($this->senhaDoFlash(), $aluno->password));
    }

    public function test_sem_permissao_da_403(): void
    {
        $funcionario = $this->utilizador('func@example.com', Perfil::FUNCIONARIO);
        $alvo = $this->utilizador('alvo@example.com');
        $hash = $alvo->password;

        $this->actingAs($funcionario)->patch("/usuarios/{$alvo->id}/redefinir-senha")->assertForbidden();

        $alvo->refresh();
        $this->assertSame($hash, $alvo->password);
        $this->assertFalse($alvo->deve_alterar_senha);
        $this->assertNull($alvo->senha_redefinida_em);
    }

    public function test_nao_redefine_utilizador_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $admin = $this->admin();
        $emA = $this->utilizador('igual@example.com');
        $emB = $this->noTenant($outro, function () {
            $user = User::create(['name' => 'B', 'email' => 'igual@example.com', 'password' => Hash::make('senha-b-123')]);
            $user->createToken('api');
            SessaoDeUtilizador::forceCreate(['id' => 'sessao-b', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);

            return $user;
        });
        $hashB = $emB->password;

        // O mesmo id, no domínio de A, não encontra o utilizador de B.
        $this->actingAs($admin)
            ->patch($this->urlDoTenant($this->tenant, "/usuarios/{$emB->id}/redefinir-senha"))
            ->assertNotFound();

        // Redefinir o homónimo de A não toca no de B.
        $this->actingAs($admin)
            ->patch($this->urlDoTenant($this->tenant, "/usuarios/{$emA->id}/redefinir-senha"))
            ->assertRedirect();

        $this->noTenant($outro, function () use ($emB, $hashB) {
            $b = User::findOrFail($emB->id);
            $this->assertSame($hashB, $b->password);
            $this->assertFalse($b->deve_alterar_senha);
            $this->assertNull($b->senha_redefinida_em);
            $this->assertSame(1, $b->tokens()->count());
        });
        $this->assertSame(1, SessaoDeUtilizador::where('user_id', $emB->id)->count());
        $this->assertTrue($emA->fresh()->deve_alterar_senha);
    }

    public function test_nao_redefine_a_propria_senha(): void
    {
        $admin = $this->admin();
        $hash = $admin->password;

        $this->actingAs($admin)->patch("/usuarios/{$admin->id}/redefinir-senha")->assertForbidden();

        $admin->refresh();
        $this->assertSame($hash, $admin->password);
        $this->assertFalse($admin->deve_alterar_senha);
    }

    public function test_so_admin_redefine_conta_de_admin(): void
    {
        $alvoAdmin = $this->admin('alvo-admin@example.com');
        $hash = $alvoAdmin->password;

        $comPermissao = $this->utilizador('func@example.com', Perfil::FUNCIONARIO);
        UserPermissao::create([
            'users_id' => $comPermissao->id,
            'modulo_id' => ModuloRegistro::where('nome', Modulo::SENHA_UTILIZADOR->value)->firstOrFail()->id,
            'acao_id' => Acao::where('nome', 'editar')->firstOrFail()->id,
            'permitido' => true,
        ]);

        // Controlo positivo: a permissão chega para um não-admin.
        $comum = $this->utilizador('comum@example.com');
        $this->actingAs($comPermissao)->patch("/usuarios/{$comum->id}/redefinir-senha")->assertRedirect();

        $this->actingAs($comPermissao)->patch("/usuarios/{$alvoAdmin->id}/redefinir-senha")->assertForbidden();
        $this->assertSame($hash, $alvoAdmin->fresh()->password);

        $this->actingAs($this->admin('outro-admin@example.com'))
            ->patch("/usuarios/{$alvoAdmin->id}/redefinir-senha")
            ->assertRedirect();
        $this->assertTrue($alvoAdmin->fresh()->deve_alterar_senha);
    }

    public function test_admin_inicial_recebe_a_permissao_por_seed(): void
    {
        $modulo = ModuloRegistro::where('nome', Modulo::SENHA_UTILIZADOR->value)->firstOrFail();
        $acao = Acao::where('nome', 'editar')->firstOrFail();

        $roles = RolePermissao::where('modulo_id', $modulo->id)->where('acao_id', $acao->id)
            ->with('role')->get()->pluck('role.nome')->all();
        $todas = RolePermissao::where('modulo_id', $modulo->id)->count();

        $this->assertSame([Perfil::ADMIN_ESCOLA->value], $roles);
        $this->assertSame(1, $todas);
    }

    public function test_utilizador_criado_pelo_formulario_nao_tem_de_alterar_a_senha(): void
    {
        $user = $this->utilizador('novo@example.com');

        $this->assertFalse($user->fresh()->deve_alterar_senha);
    }

    public function test_a_senha_nao_fica_em_claro_no_payload_da_sessao(): void
    {
        $admin = $this->admin();
        $alvo = $this->utilizador('alvo@example.com');

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/redefinir-senha");
        $senha = $this->senhaDoFlash();

        $payload = serialize(session()->all());
        $this->assertStringNotContainsString($senha, $payload);

        $pagina = $this->actingAs($admin)->withHeaders($this->cabecalhosInertia())->get('/usuarios');
        $this->assertSame($senha, $pagina->json('props.flash.senha_temporaria.senha'));
    }

    private function darPermissaoDeSenha(User $user): void
    {
        UserPermissao::create([
            'users_id' => $user->id,
            'modulo_id' => ModuloRegistro::where('nome', Modulo::SENHA_UTILIZADOR->value)->firstOrFail()->id,
            'acao_id' => Acao::where('nome', 'editar')->firstOrFail()->id,
            'permitido' => true,
        ]);
    }

    public function test_autor_com_role_admin_inactivo_nao_redefine_um_admin(): void
    {
        $alvoAdmin = $this->admin('alvo-admin@example.com');
        $autor = $this->admin('autor@example.com');
        Role::where('nome', Perfil::ADMIN_ESCOLA->value)->update(['estado' => Estado::INATIVO->value]);
        $this->darPermissaoDeSenha($autor);
        app(PermissaoCache::class)->invalidarTudo();

        $this->actingAs($autor)->patch("/usuarios/{$alvoAdmin->id}/redefinir-senha")->assertForbidden();
        $this->assertFalse($alvoAdmin->fresh()->deve_alterar_senha);
    }

    public function test_alvo_com_varios_roles_em_que_um_e_admin_exige_autor_admin(): void
    {
        $alvo = $this->utilizador('multi@example.com', Perfil::PROFESSOR);
        $alvo->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);
        $func = $this->utilizador('func@example.com', Perfil::FUNCIONARIO);
        $this->darPermissaoDeSenha($func);

        $this->actingAs($func)->patch("/usuarios/{$alvo->id}/redefinir-senha")->assertForbidden();
        $this->actingAs($this->admin())->patch("/usuarios/{$alvo->id}/redefinir-senha")->assertRedirect();
    }

    public function test_perfil_personalizado_como_alvo_e_redefinivel_e_como_autor_nao_conta_como_admin(): void
    {
        $custom = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Personalizado', 'estado' => Estado::ATIVO->value]);

        $alvoCustom = $this->utilizador('custom-alvo@example.com');
        $alvoCustom->roles()->attach($custom->id);
        $this->actingAs($this->admin())->patch("/usuarios/{$alvoCustom->id}/redefinir-senha")->assertRedirect();

        $autorCustom = $this->utilizador('custom-autor@example.com');
        $autorCustom->roles()->attach($custom->id);
        $this->darPermissaoDeSenha($autorCustom);
        $alvoAdmin = $this->admin('alvo-admin@example.com');

        $this->actingAs($autorCustom)->patch("/usuarios/{$alvoAdmin->id}/redefinir-senha")->assertForbidden();
    }

    private function darPermissao(User $user, Modulo $modulo, string $acao): void
    {
        UserPermissao::create([
            'users_id' => $user->id,
            'modulo_id' => ModuloRegistro::where('nome', $modulo->value)->firstOrFail()->id,
            'acao_id' => Acao::where('nome', $acao)->firstOrFail()->id,
            'permitido' => true,
        ]);
    }

    /** Utilizador cujo perfil personalizado concede apenas a permissão indicada. */
    private function comPerfilPersonalizado(string $email, Modulo $modulo, string $acao): User
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Personalizado ' . $email, 'estado' => Estado::ATIVO->value]);
        RolePermissao::create([
            'role_id' => $role->id,
            'modulo_id' => ModuloRegistro::where('nome', $modulo->value)->firstOrFail()->id,
            'acao_id' => Acao::where('nome', $acao)->firstOrFail()->id,
        ]);
        $user = $this->utilizador($email);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_alvos_privilegiados_sem_role_admin_exigem_autor_admin(): void
    {
        $autorPorUserPermissao = $this->utilizador('autor-up@example.com', Perfil::FUNCIONARIO);
        $this->darPermissaoDeSenha($autorPorUserPermissao);
        $autorPorPerfil = $this->comPerfilPersonalizado('autor-perfil@example.com', Modulo::SENHA_UTILIZADOR, 'editar');

        $alvos = [
            'autorizacao.editar por perfil' => $this->comPerfilPersonalizado('alvo-aut@example.com', Modulo::AUTORIZACAO, 'editar'),
            'senha-utilizador.editar por perfil' => $this->comPerfilPersonalizado('alvo-senha@example.com', Modulo::SENHA_UTILIZADOR, 'editar'),
        ];
        $porUserPermissao = $this->utilizador('alvo-up@example.com', Perfil::FUNCIONARIO);
        $this->darPermissao($porUserPermissao, Modulo::AUTORIZACAO, 'ver');
        $alvos['autorizacao.ver por user_permissoes'] = $porUserPermissao;
        app(PermissaoCache::class)->invalidarTudo();

        foreach ($alvos as $descricao => $alvo) {
            foreach ([$autorPorUserPermissao, $autorPorPerfil] as $autor) {
                $this->actingAs($autor)->patch("/usuarios/{$alvo->id}/redefinir-senha")->assertForbidden();
                $this->assertFalse($alvo->fresh()->deve_alterar_senha, $descricao);
            }

            $this->actingAs($this->admin("admin-{$alvo->id}@example.com"))->patch("/usuarios/{$alvo->id}/redefinir-senha")->assertRedirect();
            $this->assertTrue($alvo->fresh()->deve_alterar_senha, $descricao);
        }
    }

    public function test_alvo_comum_continua_redefinivel_por_quem_so_tem_a_permissao_de_senha(): void
    {
        $autor = $this->comPerfilPersonalizado('autor@example.com', Modulo::SENHA_UTILIZADOR, 'editar');
        $alvo = $this->utilizador('comum@example.com', Perfil::PROFESSOR);

        $this->actingAs($autor)->patch("/usuarios/{$alvo->id}/redefinir-senha")->assertRedirect();
        $this->assertTrue($alvo->fresh()->deve_alterar_senha);
    }
}
