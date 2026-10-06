<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Autenticacao\Actions\RecuperaAdministradorDoTenantAction;
use Modules\Autenticacao\Actions\RecuperarAdministradorAction;
use Modules\Autenticacao\Exceptions\AdministradorNaoRecuperavel;
use Modules\Core\Tenancy\Contracts\RecuperaAdministradorDoTenant;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Enums\MotivoRecusaRecuperacao;
use Modules\Core\Tenancy\Exceptions\RecuperacaoDeAdministradorRecusada;
use Modules\Core\Tenancy\Provisioning\AdministradorDaEscola;
use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * O contrato RecuperaAdministradorDoTenant (Plano 13, Task 6): implementado em Autenticacao, abre o
 * contexto da escola ele próprio, delega na RecuperarAdministradorAction e restaura o contexto.
 */
class RecuperaAdministradorDoTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        $this->a = $this->criarEscola('MOSI-000010', 'a.mositec.test', 'admin@a.test');
        $this->b = $this->criarEscola('MOSI-000011', 'b.mositec.test', 'admin@b.test');
    }

    private function criarEscola(string $codigo, string $dominio, string $email): Tenant
    {
        $this->assertSame(0, Artisan::call('mosi:tenant:create', [
            '--nome' => "Escola {$codigo}",
            '--admin-nome' => 'Admin',
            '--admin-email' => $email,
            '--dominio' => $dominio,
            '--codigo' => $codigo,
        ]), Artisan::output());

        return Tenant::where('codigo', $codigo)->sole();
    }

    private function contrato(): RecuperaAdministradorDoTenant
    {
        return app(RecuperaAdministradorDoTenant::class);
    }

    private function contexto(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** Utilizador extra da escola, com o perfil dado (ou sem perfil) e o estado dado. */
    private function utilizador(Tenant $escola, string $nome, string $email, ?Perfil $perfil = Perfil::ADMIN_ESCOLA, int $estado = 1): User
    {
        return $this->noTenant($escola, function () use ($nome, $email, $perfil, $estado) {
            $user = User::create(['name' => $nome, 'email' => $email, 'password' => Hash::make('Original-1!'), 'tipo_login' => TipoLogin::EMAIL, 'estado' => $estado]);
            $user->forceFill(['deve_alterar_senha' => false, 'remember_token' => 'remember-'.$email])->save();

            if ($perfil !== null) {
                $user->roles()->attach(Role::where('nome', $perfil->value)->sole()->id);
            }

            return $user->fresh();
        });
    }

    private function admin(Tenant $escola, string $email): User
    {
        return $this->noTenant($escola, fn () => User::where('email', $email)->sole());
    }

    private function actual(Tenant $escola): TenantAtual
    {
        return Tenant::findOrFail($escola->id)->paraTenantAtual();
    }

    private function mudarEstado(Tenant $escola, EstadoTenant $estado): void
    {
        $escola->forceFill(['estado' => $estado])->save();
    }

    /** @return array<string, mixed> o que um utilizador tem de relevante para "ficou intacto" */
    private function impressao(Tenant $escola, string $email): array
    {
        return $this->noTenant($escola, function () use ($email) {
            $user = User::where('email', $email)->sole();

            return [
                'password' => $user->password,
                'deve' => (bool) $user->deve_alterar_senha,
                'remember' => $user->remember_token,
                'sessoes' => DB::table('sessions')->where('user_id', $user->id)->count(),
                'tokens' => $user->tokens()->count(),
            ];
        });
    }

    private function abrirSessaoETokens(Tenant $escola, User $user, string $idSessao): void
    {
        DB::table('sessions')->insert(['id' => $idSessao, 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
        $this->noTenant($escola, fn () => $user->createToken('api'));
    }

    // --- Ligação do contrato ------------------------------------------------------------------

    public function test_o_contrato_esta_ligado_a_action_do_modulo_autenticacao(): void
    {
        $this->assertInstanceOf(RecuperaAdministradorDoTenantAction::class, $this->contrato());
    }

    public function test_sem_o_modulo_autenticacao_o_contrato_falha_alto_e_nunca_devolve_lista_vazia(): void
    {
        // Simula o módulo desactivado: sem o bind do AutenticacaoServiceProvider.
        unset($this->app[RecuperaAdministradorDoTenant::class]);

        $this->expectException(BindingResolutionException::class);

        app(RecuperaAdministradorDoTenant::class)->administradores($this->actual($this->a));
    }

    // --- administradores() --------------------------------------------------------------------

    public function test_administradores_devolve_so_os_activos_da_escola_alvo_ordenados_por_nome(): void
    {
        $this->utilizador($this->a, 'Zacarias', 'zacarias@a.test');
        $this->utilizador($this->a, 'Beto', 'beto@a.test');
        $this->utilizador($this->a, 'Desactivado', 'desactivado@a.test', estado: 0);
        $this->utilizador($this->a, 'Professor', 'prof@a.test', Perfil::PROFESSOR);
        $this->utilizador($this->a, 'Sem perfil', 'semperfil@a.test', null);
        $this->utilizador($this->b, 'Do outro', 'outro@b.test');

        $lista = $this->contrato()->administradores($this->actual($this->a));

        $this->assertContainsOnlyInstancesOf(AdministradorDaEscola::class, $lista);
        $this->assertTrue(array_is_list($lista));
        $this->assertSame(['Admin', 'Beto', 'Zacarias'], array_map(fn (AdministradorDaEscola $a) => $a->nome, $lista));
        $this->assertSame(['admin@a.test', 'beto@a.test', 'zacarias@a.test'], array_map(fn (AdministradorDaEscola $a) => $a->email, $lista));
    }

    public function test_a_outra_escola_lista_so_os_seus_administradores(): void
    {
        $this->utilizador($this->a, 'Beto', 'beto@a.test');

        $lista = $this->contrato()->administradores($this->actual($this->b));

        $this->assertSame(['admin@b.test'], array_map(fn (AdministradorDaEscola $a) => $a->email, $lista));
    }

    public function test_o_dto_so_tem_nome_e_email(): void
    {
        $campos = array_map(fn ($p) => $p->getName(), (new ReflectionClass(AdministradorDaEscola::class))->getProperties());
        sort($campos);

        $this->assertSame(['email', 'nome'], $campos);
        $this->assertTrue((new ReflectionClass(AdministradorDaEscola::class))->isReadOnly());
        $this->assertTrue((new ReflectionClass(AdministradorDaEscola::class))->isFinal());
    }

    public function test_administradores_recusa_escola_nao_activa(): void
    {
        $this->mudarEstado($this->a, EstadoTenant::SUSPENSO);

        try {
            $this->contrato()->administradores($this->actual($this->a));
            $this->fail('Devia recusar uma escola suspensa.');
        } catch (RecuperacaoDeAdministradorRecusada $e) {
            $this->assertSame(MotivoRecusaRecuperacao::ESCOLA_NAO_ACTIVA, $e->motivo);
        }
    }

    // --- recuperar() --------------------------------------------------------------------------

    public function test_recuperar_gera_senha_temporaria_so_hash_e_flag_e_deixa_senha_redefinida_por_nulo(): void
    {
        $admin = $this->admin($this->a, 'admin@a.test');
        $hashAntigo = $admin->password;

        $credencial = $this->contrato()->recuperar($this->actual($this->a));

        $this->assertInstanceOf(CredencialInicial::class, $credencial);
        $this->assertSame('admin@a.test', $credencial->email);
        $this->assertGreaterThanOrEqual(12, strlen($credencial->senha()));

        $depois = $this->admin($this->a, 'admin@a.test');
        $this->assertNotSame($hashAntigo, $depois->password);
        $this->assertNotSame($credencial->senha(), $depois->password);
        $this->assertTrue(Hash::check($credencial->senha(), $depois->password));
        $this->assertTrue((bool) $depois->deve_alterar_senha);
        $this->assertNull($depois->senha_redefinida_por);
        $this->assertNotNull($depois->senha_redefinida_em);
        // A senha em claro não está em nenhuma coluna do utilizador.
        $this->assertStringNotContainsString($credencial->senha(), json_encode(DB::table('users')->get()->all()));
    }

    public function test_recuperar_invalida_sessoes_tokens_e_remember_so_desse_utilizador(): void
    {
        $alvo = $this->admin($this->a, 'admin@a.test');
        $outroAdmin = $this->utilizador($this->a, 'Segundo', 'segundo@a.test');
        $homonimo = $this->utilizador($this->b, 'Homónimo', 'admin@a.test');
        $this->noTenant($this->a, fn () => $alvo->forceFill(['remember_token' => 'remember-alvo'])->save());

        $this->abrirSessaoETokens($this->a, $alvo, 'sessao-alvo');
        $this->abrirSessaoETokens($this->a, $outroAdmin, 'sessao-segundo');
        $this->abrirSessaoETokens($this->b, $homonimo, 'sessao-homonimo');
        $antesSegundo = $this->impressao($this->a, 'segundo@a.test');
        $antesHomonimo = $this->impressao($this->b, 'admin@a.test');
        $antesDoAdminDeB = $this->impressao($this->b, 'admin@b.test');

        // Há dois administradores em A: o e-mail escolhe o alvo.
        $this->contrato()->recuperar($this->actual($this->a), 'admin@a.test');

        $this->assertSame(0, DB::table('sessions')->where('id', 'sessao-alvo')->count());
        $this->assertSame(0, $this->impressao($this->a, 'admin@a.test')['tokens']);
        $this->assertNotSame('remember-alvo', $this->impressao($this->a, 'admin@a.test')['remember']);

        $this->assertSame($antesSegundo, $this->impressao($this->a, 'segundo@a.test'));
        $this->assertSame($antesHomonimo, $this->impressao($this->b, 'admin@a.test'));
        $this->assertSame($antesDoAdminDeB, $this->impressao($this->b, 'admin@b.test'));
        $this->assertSame(1, DB::table('sessions')->where('id', 'sessao-segundo')->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'sessao-homonimo')->count());
    }

    public function test_o_email_e_aceite_sem_distinguir_maiusculas_nem_espacos(): void
    {
        $this->utilizador($this->a, 'Segundo', 'segundo@a.test');

        $credencial = $this->contrato()->recuperar($this->actual($this->a), '  SEGUNDO@a.TEST ');

        $this->assertSame('segundo@a.test', $credencial->email);
    }

    public function test_recuperar_recusa_escola_nao_activa_e_nao_altera_nada(): void
    {
        foreach ([EstadoTenant::SUSPENSO, EstadoTenant::ENCERRADO] as $estado) {
            $this->mudarEstado($this->a, $estado);
            $antes = $this->impressao($this->a, 'admin@a.test');

            try {
                $this->contrato()->recuperar($this->actual($this->a));
                $this->fail("Devia recusar a escola em {$estado->name}.");
            } catch (RecuperacaoDeAdministradorRecusada $e) {
                $this->assertSame(MotivoRecusaRecuperacao::ESCOLA_NAO_ACTIVA, $e->motivo);
            }

            $this->assertSame($antes, $this->impressao($this->a, 'admin@a.test'));
        }
    }

    public function test_o_estado_da_escola_e_lido_de_novo_nao_se_confia_no_instantaneo_do_chamador(): void
    {
        $instantaneoActivo = $this->actual($this->a);
        $this->mudarEstado($this->a, EstadoTenant::SUSPENSO);

        $this->expectException(RecuperacaoDeAdministradorRecusada::class);

        $this->contrato()->recuperar($instantaneoActivo);
    }

    public function test_recuperar_recusa_administrador_desactivado(): void
    {
        $this->noTenant($this->a, fn () => $this->admin($this->a, 'admin@a.test')->forceFill(['estado' => 0])->save());
        $antes = $this->impressao($this->a, 'admin@a.test');

        try {
            $this->contrato()->recuperar($this->actual($this->a), 'admin@a.test');
            $this->fail('Devia recusar o administrador desactivado.');
        } catch (RecuperacaoDeAdministradorRecusada $e) {
            $this->assertSame(MotivoRecusaRecuperacao::DESACTIVADO, $e->motivo);
        }

        $this->assertSame($antes, $this->impressao($this->a, 'admin@a.test'));
    }

    public function test_com_varios_administradores_sem_email_falha_clara_e_nada_muda(): void
    {
        $this->utilizador($this->a, 'Segundo', 'segundo@a.test');
        $antes = [$this->impressao($this->a, 'admin@a.test'), $this->impressao($this->a, 'segundo@a.test')];

        try {
            $this->contrato()->recuperar($this->actual($this->a));
            $this->fail('Devia exigir o e-mail.');
        } catch (RecuperacaoDeAdministradorRecusada $e) {
            $this->assertSame(MotivoRecusaRecuperacao::VARIOS_ADMINISTRADORES, $e->motivo);
            $this->assertInstanceOf(AdministradorNaoRecuperavel::class, $e);
        }

        $this->assertSame($antes, [$this->impressao($this->a, 'admin@a.test'), $this->impressao($this->a, 'segundo@a.test')]);
    }

    public function test_email_inexistente_ou_de_outra_escola_tem_o_mesmo_motivo_e_nada_muda(): void
    {
        $antesDeB = $this->impressao($this->b, 'admin@b.test');
        $mensagens = [];

        foreach (['ninguem@a.test', 'admin@b.test'] as $email) {
            try {
                $this->contrato()->recuperar($this->actual($this->a), $email);
                $this->fail("Devia recusar {$email}.");
            } catch (RecuperacaoDeAdministradorRecusada $e) {
                $this->assertSame(MotivoRecusaRecuperacao::NAO_ENCONTRADO, $e->motivo);
                $mensagens[] = $e->getMessage();
            }
        }

        $this->assertSame($mensagens[0], $mensagens[1]);
        $this->assertSame($antesDeB, $this->impressao($this->b, 'admin@b.test'));
    }

    public function test_escola_sem_administradores_tem_motivo_proprio(): void
    {
        $this->noTenant($this->a, fn () => $this->admin($this->a, 'admin@a.test')->roles()->detach());

        try {
            $this->contrato()->recuperar($this->actual($this->a));
            $this->fail('Devia recusar: sem administradores.');
        } catch (RecuperacaoDeAdministradorRecusada $e) {
            $this->assertSame(MotivoRecusaRecuperacao::SEM_ADMINISTRADORES, $e->motivo);
        }
    }

    // --- Contexto -----------------------------------------------------------------------------

    public function test_ao_entrar_com_contexto_vazio_sai_vazio_em_sucesso_e_em_recusa(): void
    {
        $this->contexto()->limpar();

        $this->contrato()->administradores($this->actual($this->a));
        $this->assertFalse($this->contexto()->temTenant());

        $this->contrato()->recuperar($this->actual($this->a));
        $this->assertFalse($this->contexto()->temTenant());

        try {
            $this->contrato()->recuperar($this->actual($this->a), 'ninguem@a.test');
        } catch (RecuperacaoDeAdministradorRecusada) {
        }
        $this->assertFalse($this->contexto()->temTenant());

        $this->mudarEstado($this->a, EstadoTenant::SUSPENSO);
        try {
            $this->contrato()->recuperar($this->actual($this->a));
        } catch (RecuperacaoDeAdministradorRecusada) {
        }
        $this->assertFalse($this->contexto()->temTenant());
    }

    public function test_ao_entrar_com_outro_tenant_aberto_volta_a_esse_tenant(): void
    {
        $this->contexto()->definir($this->actual($this->b));

        $this->contrato()->administradores($this->actual($this->a));
        $this->assertSame($this->b->id, $this->contexto()->id());

        $credencial = $this->contrato()->recuperar($this->actual($this->a));
        $this->assertSame('admin@a.test', $credencial->email);
        $this->assertSame($this->b->id, $this->contexto()->id());

        try {
            $this->contrato()->recuperar($this->actual($this->a), 'ninguem@a.test');
        } catch (RecuperacaoDeAdministradorRecusada) {
        }
        $this->assertSame($this->b->id, $this->contexto()->id());
    }

    public function test_o_contexto_e_restaurado_mesmo_com_excepcao_inesperada(): void
    {
        $this->contexto()->limpar();
        $this->contexto()->definir($this->actual($this->b));

        // Falha a meio da execução, já dentro do contexto da escola A.
        $this->app->bind(RecuperarAdministradorAction::class, fn () => new class extends RecuperarAdministradorAction
        {
            public function __construct() {}

            public function executar(?string $email = null): CredencialInicial
            {
                throw new RuntimeException('falha inesperada');
            }
        });

        try {
            app(RecuperaAdministradorDoTenant::class)->recuperar($this->actual($this->a));
            $this->fail('A excepção devia propagar-se.');
        } catch (RuntimeException $e) {
            $this->assertSame('falha inesperada', $e->getMessage());
        }

        $this->assertSame($this->b->id, $this->contexto()->id());
    }

    public function test_a_senha_nunca_vai_para_os_logs(): void
    {
        $logs = [];
        Log::listen(function ($m) use (&$logs) {
            $logs[] = $m->message.' '.json_encode($m->context);
        });

        $credencial = $this->contrato()->recuperar($this->actual($this->a));

        $this->assertStringNotContainsString($credencial->senha(), implode("\n", $logs));
    }
}
