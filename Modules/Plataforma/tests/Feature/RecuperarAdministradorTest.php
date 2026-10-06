<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Modules\Autenticacao\Actions\RecuperaAdministradorDoTenantAction;
use Modules\Core\Tenancy\Contracts\RecuperaAdministradorDoTenant;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Enums\MotivoRecusaRecuperacao;
use Modules\Core\Tenancy\Exceptions\RecuperacaoDeAdministradorRecusada;
use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use RuntimeException;
use Tests\TestCase;

/**
 * Recuperar o administrador de uma escola, pelo painel. O painel corre sempre sem contexto de tenant:
 * só o contrato (implementado em Autenticacao) o abre, por dentro. A senha temporária aparece uma
 * única vez e o painel só obtém nome, e-mail e estado dos administradores.
 */
class RecuperarAdministradorTest extends TestCase
{
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private const MARCADOR_ACADEMICO = 'AAA-DADO-ACADEMICO';

    private SuperAdmin $admin;

    private string $sessao;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        config(['tenancy.hosts_centrais' => [self::CENTRAL]]);
        config(['session.driver' => 'database']);
        // Sem a lotaria de limpeza de sessões: acrescentaria consultas ao registo contado abaixo.
        config(['session.lottery' => [0, 100]]);

        $this->a = $this->criarEscola('MOSI-000010', 'a.mositec.test', 'admin@a.test');
        $this->b = $this->criarEscola('MOSI-000011', 'b.mositec.test', 'admin@b.test');

        $this->admin = $this->superAdmin();
        $this->sessao = $this->entrarNoPainel($this->admin);
    }

    // --- Auxiliares --------------------------------------------------------------------------

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

    private function utilizador(Tenant $escola, string $nome, string $email, ?Perfil $perfil = Perfil::ADMIN_ESCOLA, int $estado = 1): User
    {
        return $this->noTenant($escola, function () use ($nome, $email, $perfil, $estado) {
            $user = User::create(['name' => $nome, 'email' => $email, 'password' => Hash::make('Original-1!'), 'tipo_login' => TipoLogin::EMAIL, 'estado' => $estado]);
            $user->forceFill(['deve_alterar_senha' => false])->save();

            if ($perfil !== null) {
                $user->roles()->attach(Role::where('nome', $perfil->value)->sole()->id);
            }

            return $user->fresh();
        });
    }

    private function hashDe(Tenant $escola, string $email): string
    {
        return $this->noTenant($escola, fn () => User::where('email', $email)->sole()->password);
    }

    private function estado(Tenant $escola, EstadoTenant $estado): void
    {
        $escola->forceFill(['estado' => $estado])->save();
    }

    private function inertiaGet(string $caminho): TestResponse
    {
        return $this->noPainel('GET', $caminho, $this->sessao, cabecalhos: $this->cabecalhosInertia());
    }

    private function listar(Tenant $escola, ?string $sessao = null): TestResponse
    {
        return $this->noPainel('GET', "/plataforma/escolas/{$escola->codigo}/administradores", $sessao ?? $this->sessao, cabecalhos: ['Accept' => 'application/json']);
    }

    private function recuperar(Tenant $escola, array $dados = [], ?string $sessao = null): TestResponse
    {
        return $this->noPainel('POST', "/plataforma/escolas/{$escola->codigo}/administrador/recuperar", $sessao ?? $this->sessao, $dados);
    }

    private function urlDaEscola(Tenant $escola): string
    {
        return $this->urlCentral("/plataforma/escolas/{$escola->codigo}");
    }

    private function auditoria(string $codigo)
    {
        return RegistoDeAuditoria::where('codigo_tenant', $codigo)->where('accao', 'administrador.recuperado')->get();
    }

    private function payloadsDasSessoes(): string
    {
        return DB::table('sessions')->pluck('payload')->map(fn ($p) => base64_decode($p).$p)->implode("\n");
    }

    private function activarCsrfReal(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    /**
     * Substitui o contrato por um espião que regista se havia contexto de tenant antes e depois de
     * cada chamada ao contrato real (que é quem o abre, por dentro).
     *
     * @param  array<int, array{string, bool, bool}>  $observacoes
     */
    private function espiarOContrato(array &$observacoes): void
    {
        $real = app(RecuperaAdministradorDoTenantAction::class);
        $contexto = app(TenantContext::class);

        $this->app->instance(RecuperaAdministradorDoTenant::class, new class($real, $contexto, $observacoes) implements RecuperaAdministradorDoTenant
        {
            public function __construct(private $real, private TenantContext $contexto, private array &$observacoes) {}

            public function administradores(TenantAtual $tenant): array
            {
                $antes = $this->contexto->temTenant();

                try {
                    return $this->real->administradores($tenant);
                } finally {
                    $this->observacoes[] = ['administradores', $antes, $this->contexto->temTenant()];
                }
            }

            public function recuperar(TenantAtual $tenant, ?string $email = null): CredencialInicial
            {
                $antes = $this->contexto->temTenant();

                try {
                    return $this->real->recuperar($tenant, $email);
                } finally {
                    $this->observacoes[] = ['recuperar', $antes, $this->contexto->temTenant()];
                }
            }
        });
    }

    // --- Listar administradores (a pedido, ao abrir o modal) ---------------------------------

    public function test_o_endpoint_lista_so_nome_e_email_dos_administradores_activos_da_escola(): void
    {
        $this->utilizador($this->a, 'Zacarias', 'zacarias@a.test');
        $this->utilizador($this->a, 'Desactivado', 'desactivado@a.test', estado: 0);
        $this->utilizador($this->a, self::MARCADOR_ACADEMICO, 'prof@a.test', Perfil::PROFESSOR);
        $this->utilizador($this->b, 'Do outro', 'outro@b.test');

        $resposta = $this->listar($this->a);

        $resposta->assertOk();
        $this->assertSame([
            ['nome' => 'Admin', 'email' => 'admin@a.test'],
            ['nome' => 'Zacarias', 'email' => 'zacarias@a.test'],
        ], $resposta->json('administradores'));
        $this->assertSame(['administradores'], array_keys($resposta->json()));

        $corpo = $resposta->getContent();
        foreach (['desactivado@a.test', self::MARCADOR_ACADEMICO, 'prof@a.test', 'outro@b.test', 'admin@b.test'] as $nao) {
            $this->assertStringNotContainsString($nao, $corpo);
        }
        // Nada de perfis, permissões ou ids.
        foreach (['role', 'perfil', 'permiss', 'tenant_id', 'password', 'admin_escola'] as $nao) {
            $this->assertStringNotContainsString($nao, strtolower($corpo));
        }
    }

    public function test_o_pedido_de_listar_corre_com_contexto_vazio_e_so_o_contrato_o_abre(): void
    {
        $observacoes = [];
        $this->espiarOContrato($observacoes);

        $this->listar($this->a)->assertOk();

        $this->assertSame([['administradores', false, false]], $observacoes);
        $this->assertFalse(app(TenantContext::class)->temTenant());
    }

    public function test_o_detalhe_da_escola_nao_abre_contexto_nem_le_utilizadores_em_cada_visita(): void
    {
        $this->utilizador($this->a, 'Zacarias', 'zacarias@a.test');
        $observacoes = [];
        $this->espiarOContrato($observacoes);
        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });

        $resposta = $this->inertiaGet("/plataforma/escolas/{$this->a->codigo}");

        $resposta->assertOk();
        $this->assertSame([], $observacoes, 'O detalhe não chama o contrato.');
        $this->assertSame([], array_values(array_filter($consultas, fn ($sql) => preg_match('/\b(from|join)\s+"?(users|roles|user_roles|sessions)"?/i', $sql) === 1 && ! str_contains($sql, 'sessions'))));
        $this->assertStringNotContainsString('zacarias@a.test', $resposta->getContent());
        $this->assertStringNotContainsString('admin@a.test', $resposta->getContent());
    }

    public function test_listar_numa_escola_nao_activa_e_recusado_com_mensagem(): void
    {
        foreach ([EstadoTenant::SUSPENSO, EstadoTenant::ENCERRADO] as $estado) {
            $this->estado($this->a, $estado);

            $resposta = $this->listar($this->a);

            $resposta->assertStatus(422);
            $this->assertNotEmpty($resposta->json('message'));
            $this->assertArrayNotHasKey('administradores', $resposta->json());
        }
    }

    public function test_listar_numa_escola_inexistente_da_404(): void
    {
        $this->noPainel('GET', '/plataforma/escolas/MOSI-999999/administradores', $this->sessao, cabecalhos: ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_sem_o_modulo_autenticacao_o_endpoint_falha_alto_nunca_devolve_lista_vazia(): void
    {
        unset($this->app[RecuperaAdministradorDoTenant::class]);
        $this->withoutExceptionHandling();

        $this->expectException(BindingResolutionException::class);

        $this->listar($this->a);
    }

    public function test_sem_o_modulo_autenticacao_recuperar_falha_alto_e_nada_muda(): void
    {
        $antes = $this->hashDe($this->a, 'admin@a.test');
        unset($this->app[RecuperaAdministradorDoTenant::class]);
        $this->withoutExceptionHandling();

        try {
            $this->recuperar($this->a);
            $this->fail('Devia falhar alto.');
        } catch (BindingResolutionException) {
        }

        $this->assertSame($antes, $this->hashDe($this->a, 'admin@a.test'));
        $this->assertSame(0, $this->auditoria($this->a->codigo)->count());
    }

    // --- Recuperar ---------------------------------------------------------------------------

    public function test_recuperar_corre_com_contexto_vazio_e_so_o_contrato_o_abre(): void
    {
        $observacoes = [];
        $this->espiarOContrato($observacoes);
        $antes = $this->hashDe($this->a, 'admin@a.test');

        $this->recuperar($this->a)->assertRedirect($this->urlDaEscola($this->a));

        $this->assertSame([['recuperar', false, false]], $observacoes);
        $this->assertFalse(app(TenantContext::class)->temTenant());
        // O contrato fez o trabalho no contexto da escola: a senha mudou.
        $this->assertNotSame($antes, $this->hashDe($this->a, 'admin@a.test'));
    }

    public function test_a_senha_temporaria_aparece_uma_so_vez_e_nunca_em_claro_no_resto(): void
    {
        // O histórico só se cifra com HTTPS (ver HistoricoCifrado): os testes correm em HTTP.
        config(['session.secure' => true]);

        $logs = [];
        Log::listen(function ($m) use (&$logs) {
            $logs[] = $m->message.' '.json_encode($m->context);
        });

        $resposta = $this->recuperar($this->a);
        $resposta->assertRedirect($this->urlDaEscola($this->a));
        $resposta->assertSessionHas('success');

        // O payload bruto da sessão guarda o flash, mas cifrado.
        $payload = $this->payloadsDasSessoes();
        $this->assertStringContainsString('senha_temporaria', $payload);

        $primeira = $this->inertiaGet("/plataforma/escolas/{$this->a->codigo}");
        $primeira->assertOk();
        $flash = $primeira->json('props.flash.senha_temporaria');
        $this->assertSame($this->a->codigo, $flash['codigo']);
        $this->assertSame('admin@a.test', $flash['email']);
        $senha = $flash['senha'];
        $this->assertGreaterThanOrEqual(12, strlen($senha));
        $this->assertTrue($primeira->json('encryptHistory'));

        $semFlash = $primeira->json('props');
        unset($semFlash['flash']['senha_temporaria']);
        $this->assertStringNotContainsString($senha, json_encode($semFlash));
        $this->assertStringNotContainsString($senha, $payload);

        $segunda = $this->inertiaGet("/plataforma/escolas/{$this->a->codigo}");
        $this->assertNull($segunda->json('props.flash.senha_temporaria'));
        $this->assertFalse((bool) $segunda->json('encryptHistory'));
        $this->assertStringNotContainsString($senha, $segunda->getContent());
        $this->assertStringNotContainsString($senha, $this->payloadsDasSessoes());

        // Nunca em logs nem na auditoria; na BD só o hash.
        $this->assertStringNotContainsString($senha, implode("\n", $logs));
        $this->assertStringNotContainsString($senha, json_encode(DB::table('plataforma_auditoria')->get()->all()));
        $hash = $this->hashDe($this->a, 'admin@a.test');
        $this->assertNotSame($senha, $hash);
        $this->assertTrue(password_verify($senha, $hash));
    }

    public function test_a_auditoria_leva_o_email_e_nunca_a_senha_e_so_em_sucesso(): void
    {
        $this->recuperar($this->a);

        $registo = $this->auditoria($this->a->codigo)->sole();
        $this->assertSame($this->admin->id, $registo->super_admin_id);
        $this->assertSame(['email' => 'admin@a.test'], $registo->detalhe);

        // Recusa: nada de auditoria nova.
        $this->estado($this->b, EstadoTenant::SUSPENSO);
        $this->recuperar($this->b);
        $this->recuperar($this->a, ['email' => 'ninguem@a.test']);

        $this->assertSame(0, $this->auditoria($this->b->codigo)->count());
        $this->assertSame(1, $this->auditoria($this->a->codigo)->count());
    }

    public function test_uma_falha_da_auditoria_faz_report_e_o_operador_nao_perde_a_senha(): void
    {
        Exceptions::fake();
        $this->mock(RegistarAuditoriaAction::class, fn (MockInterface $m) => $m->shouldReceive('executar')->andThrow(new RuntimeException('auditoria em baixo')));
        $antes = $this->hashDe($this->a, 'admin@a.test');

        $resposta = $this->recuperar($this->a);

        $resposta->assertSessionHas('success');
        $resposta->assertSessionHas('senha_temporaria');
        $this->assertNotSame($antes, $this->hashDe($this->a, 'admin@a.test'));
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'auditoria em baixo');
    }

    public function test_com_varios_administradores_sem_email_o_erro_e_claro_e_nada_muda(): void
    {
        $this->utilizador($this->a, 'Segundo', 'segundo@a.test');
        $antes = [$this->hashDe($this->a, 'admin@a.test'), $this->hashDe($this->a, 'segundo@a.test')];

        $resposta = $this->recuperar($this->a);

        $resposta->assertRedirect($this->urlDaEscola($this->a));
        $resposta->assertSessionHasErrors('email');
        $this->assertStringContainsString('vários administradores', session('errors')->first('email'));
        $this->assertStringContainsString('escolha', mb_strtolower(session('errors')->first('email')));
        $resposta->assertSessionMissing('senha_temporaria');
        $this->assertSame($antes, [$this->hashDe($this->a, 'admin@a.test'), $this->hashDe($this->a, 'segundo@a.test')]);
        $this->assertSame(0, $this->auditoria($this->a->codigo)->count());
    }

    public function test_com_varios_administradores_o_email_escolhido_e_o_unico_recuperado(): void
    {
        $this->utilizador($this->a, 'Segundo', 'segundo@a.test');
        $adminAntes = $this->hashDe($this->a, 'admin@a.test');

        $this->recuperar($this->a, ['email' => 'segundo@a.test'])->assertSessionHas('senha_temporaria');

        $this->assertSame($adminAntes, $this->hashDe($this->a, 'admin@a.test'));
        $this->assertSame('segundo@a.test', $this->auditoria($this->a->codigo)->sole()->detalhe['email']);
    }

    public function test_email_de_outra_escola_ou_inexistente_da_o_mesmo_erro_generico(): void
    {
        $antesDeB = $this->hashDe($this->b, 'admin@b.test');

        $outraEscola = $this->recuperar($this->a, ['email' => 'admin@b.test']);
        $outraEscola->assertSessionHasErrors('email');
        $erroOutraEscola = session('errors')->all();

        $inexistente = $this->recuperar($this->a, ['email' => 'ninguem@nenhuma.test']);
        $inexistente->assertSessionHasErrors('email');
        $erroInexistente = session('errors')->all();

        $this->assertSame($erroOutraEscola, $erroInexistente, 'A mensagem não distingue os dois casos.');
        $this->assertStringNotContainsString('admin@b.test', implode(' ', $erroOutraEscola));
        $this->assertStringNotContainsString('outra escola', mb_strtolower(implode(' ', $erroOutraEscola)));
        $this->assertSame($antesDeB, $this->hashDe($this->b, 'admin@b.test'));
        $this->assertSame(0, RegistoDeAuditoria::where('accao', 'administrador.recuperado')->count());
    }

    public function test_administrador_desactivado_e_recusado_com_mensagem(): void
    {
        $this->utilizador($this->a, 'Inactivo', 'inactivo@a.test', estado: 0);
        $antes = $this->hashDe($this->a, 'inactivo@a.test');

        $this->recuperar($this->a, ['email' => 'inactivo@a.test'])->assertSessionHasErrors('email');

        $this->assertStringContainsString('desactivad', session('errors')->first('email'));
        $this->assertSame($antes, $this->hashDe($this->a, 'inactivo@a.test'));
    }

    public function test_desactivado_sem_email_indicado_vai_para_o_erro_geral_e_nao_para_o_campo_email(): void
    {
        $this->app->instance(RecuperaAdministradorDoTenant::class, new class implements RecuperaAdministradorDoTenant
        {
            public function administradores(TenantAtual $tenant): array
            {
                return [];
            }

            public function recuperar(TenantAtual $tenant, ?string $email = null): CredencialInicial
            {
                throw new RecuperacaoDeAdministradorRecusada('desactivado', MotivoRecusaRecuperacao::DESACTIVADO);
            }
        });

        $this->recuperar($this->a)->assertSessionHasErrors('geral')->assertSessionDoesntHaveErrors('email');
    }

    public function test_escola_sem_administradores_da_erro_geral(): void
    {
        $this->noTenant($this->a, fn () => User::where('email', 'admin@a.test')->sole()->roles()->detach());

        $this->recuperar($this->a)->assertSessionHasErrors('geral');
    }

    public function test_escola_suspensa_ou_encerrada_e_recusada_e_nada_muda(): void
    {
        foreach ([EstadoTenant::SUSPENSO, EstadoTenant::ENCERRADO] as $estado) {
            $this->estado($this->a, $estado);
            $antes = $this->hashDe($this->a, 'admin@a.test');

            $resposta = $this->recuperar($this->a);

            $resposta->assertRedirect($this->urlDaEscola($this->a));
            $resposta->assertSessionHasErrors('geral');
            $resposta->assertSessionMissing('senha_temporaria');
            $this->assertSame($antes, $this->hashDe($this->a, 'admin@a.test'), $estado->name);
        }

        $this->assertSame(0, $this->auditoria($this->a->codigo)->count());
    }

    public function test_o_formulario_so_valida_o_formato_do_email(): void
    {
        $antes = $this->hashDe($this->a, 'admin@a.test');

        $this->recuperar($this->a, ['email' => 'isto-nao-e-um-email'])->assertSessionHasErrors('email');
        $this->recuperar($this->a, ['email' => ['a@a.test']])->assertSessionHasErrors('email');
        $this->recuperar($this->a, ['email' => str_repeat('a', 300).'@a.test'])->assertSessionHasErrors('email');

        $this->assertSame($antes, $this->hashDe($this->a, 'admin@a.test'));
    }

    public function test_email_em_branco_conta_como_nao_indicado(): void
    {
        $this->recuperar($this->a, ['email' => ''])->assertSessionHas('senha_temporaria');
    }

    public function test_so_o_administrador_alvo_e_afectado_e_a_outra_escola_fica_intacta(): void
    {
        $antesB = $this->hashDe($this->b, 'admin@b.test');
        $homonimo = $this->utilizador($this->b, 'Homónimo', 'segundo@a.test');
        $this->utilizador($this->a, 'Segundo', 'segundo@a.test');
        $antesHomonimo = $this->hashDe($this->b, 'segundo@a.test');

        $this->recuperar($this->a, ['email' => 'segundo@a.test'])->assertSessionHas('senha_temporaria');

        $this->assertSame($antesB, $this->hashDe($this->b, 'admin@b.test'));
        $this->assertSame($antesHomonimo, $this->hashDe($this->b, 'segundo@a.test'));
        $this->assertSame($homonimo->id, $this->noTenant($this->b, fn () => User::where('email', 'segundo@a.test')->sole()->id));
    }

    public function test_as_sessoes_da_plataforma_nao_sao_tocadas(): void
    {
        $this->recuperar($this->a)->assertSessionHas('senha_temporaria');

        $this->assertSame(1, DB::table('sessions')->where('id', $this->sessao)->count());
        $this->inertiaGet('/plataforma/escolas')->assertOk();
    }

    // --- O painel só vê o que o contrato devolve ----------------------------------------------

    public function test_nenhuma_resposta_do_painel_leva_dados_de_escola_alem_do_nome_e_email_do_administrador(): void
    {
        $this->utilizador($this->a, self::MARCADOR_ACADEMICO.'-PROF', 'prof-academico@a.test', Perfil::PROFESSOR);
        $this->utilizador($this->a, self::MARCADOR_ACADEMICO.'-ALUNO', 'aluno-academico@a.test', Perfil::ALUNO);

        $respostas = [
            $this->listar($this->a),
            $this->recuperar($this->a),
            $this->inertiaGet("/plataforma/escolas/{$this->a->codigo}"),
            $this->noPainel('GET', "/plataforma/escolas/{$this->a->codigo}", $this->sessao),
            $this->noPainel('GET', '/plataforma/escolas', $this->sessao),
        ];

        foreach ($respostas as $i => $resposta) {
            $corpo = $resposta->getContent();
            $this->assertStringNotContainsString(self::MARCADOR_ACADEMICO, $corpo, "Resposta {$i}");
            $this->assertStringNotContainsString('academico@a.test', $corpo, "Resposta {$i}");
        }
        $this->assertStringNotContainsString(self::MARCADOR_ACADEMICO, $this->payloadsDasSessoes());
    }

    // --- Acesso, CSRF e fronteira ---------------------------------------------------------------

    public function test_sem_token_csrf_o_pedido_da_419_e_nada_muda(): void
    {
        $this->activarCsrfReal();
        $antes = $this->hashDe($this->a, 'admin@a.test');

        $this->recuperar($this->a)->assertStatus(419);

        $this->assertSame($antes, $this->hashDe($this->a, 'admin@a.test'));
        $this->assertSame(0, $this->auditoria($this->a->codigo)->count());
    }

    public function test_com_o_token_csrf_do_painel_o_pedido_passa(): void
    {
        $this->activarCsrfReal();
        $token = $this->inertiaGet("/plataforma/escolas/{$this->a->codigo}")->json('props.csrf_token');

        $this->noPainel('POST', "/plataforma/escolas/{$this->a->codigo}/administrador/recuperar", $this->sessao, [], ['X-CSRF-TOKEN' => $token])
            ->assertRedirect($this->urlDaEscola($this->a));

        $this->assertSame(1, $this->auditoria($this->a->codigo)->count());
    }

    public function test_sem_autenticacao_vai_para_o_login_e_nada_muda(): void
    {
        $antes = $this->hashDe($this->a, 'admin@a.test');

        $this->pedido('POST', $this->urlCentral("/plataforma/escolas/{$this->a->codigo}/administrador/recuperar"))->assertRedirect($this->urlCentral('/plataforma/login'));
        $this->pedido('GET', $this->urlCentral("/plataforma/escolas/{$this->a->codigo}/administradores"))->assertRedirect($this->urlCentral('/plataforma/login'));

        $this->assertSame($antes, $this->hashDe($this->a, 'admin@a.test'));
    }

    public function test_conta_desactivada_com_sessao_aberta_e_expulsa(): void
    {
        $this->admin->forceFill(['estado' => 0])->save();
        $antes = $this->hashDe($this->a, 'admin@a.test');

        $this->recuperar($this->a)->assertRedirect($this->urlCentral('/plataforma/login'));
        // Pedido JSON (o do modal): 401, sem lista nenhuma, e o modal reencaminha para o login.
        $json = $this->listar($this->a);
        $json->assertUnauthorized();
        $this->assertStringNotContainsString('admin@a.test', $json->getContent());

        $this->assertSame($antes, $this->hashDe($this->a, 'admin@a.test'));
    }

    public function test_fora_do_host_central_da_404(): void
    {
        $antes = $this->hashDe($this->a, 'admin@a.test');

        $this->pedido('POST', 'http://a.mositec.test/plataforma/escolas/'.$this->a->codigo.'/administrador/recuperar', cookies: [])->assertNotFound();
        $this->pedido('GET', 'http://a.mositec.test/plataforma/escolas/'.$this->a->codigo.'/administradores')->assertNotFound();

        $this->assertSame($antes, $this->hashDe($this->a, 'admin@a.test'));
    }

    // --- Detalhe: o botão só para escolas activas -----------------------------------------------

    public function test_o_detalhe_so_oferece_recuperar_administrador_em_escolas_activas(): void
    {
        $esperado = [
            [EstadoTenant::ACTIVO, true],
            [EstadoTenant::SUSPENSO, false],
            [EstadoTenant::ENCERRADO, false],
        ];

        foreach ($esperado as [$estado, $oferece]) {
            $this->estado($this->a, $estado);

            $accoes = $this->inertiaGet("/plataforma/escolas/{$this->a->codigo}")->json('props.escola.accoes_permitidas');

            $this->assertSame($oferece, $accoes['recuperar_administrador'], $estado->name);
        }
    }

    // --- Arquitectura ---------------------------------------------------------------------------

    public function test_o_controller_so_conhece_o_contrato_do_core(): void
    {
        $codigo = file_get_contents(base_path('Modules/Plataforma/app/Http/Controllers/AdministradorEscolaController.php'));

        $this->assertStringContainsString('RecuperaAdministradorDoTenant', $codigo);
        $this->assertStringNotContainsString('Modules\\Autenticacao', $codigo);
        $this->assertStringNotContainsString('Modules\\Usuario', $codigo);
        $this->assertStringNotContainsString('Modules\\Permissao', $codigo);
        $this->assertStringNotContainsString('RecuperarAdministradorAction', $codigo);
        $this->assertStringNotContainsString('executarComo', $codigo);
        $this->assertStringNotContainsString('TenantContext', $codigo);
    }

    public function test_em_http_a_resposta_com_a_senha_nao_pede_a_cifra_do_historico(): void
    {
        // Regressão do "loop" no browser: em HTTP não há `crypto.subtle`, o Inertia não consegue cifrar
        // o histórico e a visita nunca termina, embora a recuperação já esteja feita no servidor.
        config(['session.secure' => false]);

        $this->recuperar($this->a)->assertRedirect($this->urlDaEscola($this->a));

        $resposta = $this->inertiaGet("/plataforma/escolas/{$this->a->codigo}");
        $resposta->assertOk();
        $this->assertNotNull($resposta->json('props.flash.senha_temporaria'));
        $this->assertFalse((bool) $resposta->json('encryptHistory'));
    }
}
