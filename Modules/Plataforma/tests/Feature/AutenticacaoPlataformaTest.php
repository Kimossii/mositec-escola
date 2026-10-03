<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Autenticacao\Actions\RevogarAcessosDoTenantAction;
use Modules\Core\Tenancy\TenantContext;
use Modules\Plataforma\Http\Middleware\ConfigurarSessaoPlataforma;
use Modules\Plataforma\Http\Middleware\HandleInertiaPlataforma;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Usuario\Actions\AlterarPropriaSenhaAction;
use Modules\Usuario\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Fixtures\Plataforma\EspiaDeContexto;
use Tests\TestCase;

/**
 * Autenticação do painel da Plataforma: login, limitador, troca de senha, expulsão de sessões
 * invalidadas, separação dos dois mundos e CSRF. Cada pedido simula um processo novo (guards e
 * Store de sessão limpos): em produção cada pedido é um processo, e nos testes o Store é
 * reutilizado e herdaria atributos de pedidos anteriores.
 */
class AutenticacaoPlataformaTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL = 'painel.mositec.test';

    private const ESCOLA = 'http://localhost';

    private const SENHA = 'Senha-Muito-Segura-1!';

    private const NOVA = 'Outra-Senha-Segura-2@';

    private const MENSAGEM_GENERICA = 'Credenciais inválidas.';

    private int $efeitos = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.hosts_centrais' => [self::CENTRAL]]);

        // Rotas-fixture com EXACTAMENTE o middleware das rotas autenticadas reais do painel.
        $middleware = Route::getRoutes()->getByName('plataforma.inicio')->gatherMiddleware();
        Route::middleware($middleware)->get('/plataforma/_auth/leitura', fn () => 'leitura-ok');
        Route::middleware($middleware)->post('/plataforma/_auth/escrita', function () {
            $this->efeitos++;

            return 'escrita-ok';
        });
    }

    // --- Auxiliares --------------------------------------------------------------------------

    private function url(string $caminho): string
    {
        return 'http://' . self::CENTRAL . $caminho;
    }

    private function admin(string $email = 'rui@plataforma.test', bool $deveAlterar = false, bool $activo = true, string $senha = self::SENHA): SuperAdmin
    {
        $admin = SuperAdmin::create([
            'name' => 'Rui Operador',
            'email' => $email,
            'password' => Hash::make($senha),
            'estado' => $activo ? 1 : 0,
        ]);

        if ($deveAlterar) {
            $admin->forceFill(['deve_alterar_senha' => true])->save();
        }

        return $admin;
    }

    /** Pedido como se fosse um processo novo; o contexto de tenant começa (e, no painel, acaba) vazio. */
    private function pedido(
        string $metodo,
        string $url,
        array $dados = [],
        array $cookies = [],
        array $cabecalhos = [],
        string $ip = '10.0.0.1',
        bool $processoNovo = true,
    ): TestResponse {
        app(TenantContext::class)->limpar();

        if ($processoNovo) {
            Auth::forgetGuards();
            // O handler de sessão da BD obtém o utilizador pelo contrato Guard (singleton `auth.driver`): num
            // processo novo ainda não existe, e se sobrevivesse levaria o guard `web` do pedido anterior.
            $this->app->forgetInstance('auth.driver');
            // Só os drivers já criados: criar o driver aqui fixaria os minutos da sessão antes do painel.
            foreach (app('session')->getDrivers() as $driver) {
                $driver->flush();

                // O handler de BD recorda se a linha da sessão anterior existia: num processo novo não recorda nada.
                if (method_exists($driver->getHandler(), 'setExists')) {
                    $driver->getHandler()->setExists(false);
                }
            }
        }

        $this->defaultCookies = $cookies;
        $resposta = $this->call(
            $metodo,
            $url,
            $dados,
            $this->prepareCookiesForRequest(),
            [],
            array_merge($this->transformHeadersToServerVars($cabecalhos), ['REMOTE_ADDR' => $ip]),
        );
        $this->defaultCookies = [];

        if (str_contains($url, self::CENTRAL)) {
            $this->assertFalse(app(TenantContext::class)->temTenant(), "Contexto de tenant aberto depois de {$metodo} {$url}");
        }

        return $resposta;
    }

    private function inertia(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaPlataforma::class)->version(Request::create('/')),
        ];
    }

    private function nomeDoCookie(): string
    {
        return ConfigurarSessaoPlataforma::nomeDoCookie();
    }

    private function idDaSessao(TestResponse $resposta, string $nome): string
    {
        $cookie = $resposta->getCookie($nome, false);
        $this->assertNotNull($cookie, "A resposta devia emitir o cookie '{$nome}'.");

        return CookieValuePrefix::remove(Crypt::decrypt($cookie->getValue(), false));
    }

    /** Login real no painel; devolve o id da sessão autenticada. */
    private function entrar(SuperAdmin $admin, string $senha = self::SENHA, string $ip = '10.0.0.1'): string
    {
        $resposta = $this->pedido('POST', $this->url('/plataforma/login'), ['email' => $admin->email, 'password' => $senha], ip: $ip);
        $resposta->assertRedirect($this->url('/plataforma'));

        return $this->idDaSessao($resposta, $this->nomeDoCookie());
    }

    /** Pedido ao painel com uma sessão (id) já aberta. */
    private function noPainel(string $metodo, string $caminho, string $sessao, array $dados = [], array $cabecalhos = []): TestResponse
    {
        return $this->pedido($metodo, $this->url($caminho), $dados, [$this->nomeDoCookie() => $sessao], $cabecalhos);
    }

    private function entrarNaEscola(User $user, string $senha = 'password123', string $ip = '10.0.0.1'): string
    {
        $resposta = $this->pedido('POST', self::ESCOLA . '/login', ['login' => $user->email, 'password' => $senha], ip: $ip);
        $resposta->assertRedirect('/');

        return $this->idDaSessao($resposta, (string) config('session.cookie'));
    }

    private function naEscola(string $caminho, string $sessao): TestResponse
    {
        return $this->pedido('GET', self::ESCOLA . $caminho, [], [(string) config('session.cookie') => $sessao]);
    }

    private function utilizadorDeEscola(string $email = 'rui@plataforma.test'): User
    {
        return User::create(['name' => 'Escola', 'email' => $email, 'password' => Hash::make('password123')]);
    }

    private function activarCsrfReal(): void
    {
        // O framework salta a verificação de CSRF em testes (runningUnitTests): usa-se o middleware real.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function falhar(string $email, int $vezes, string $ip = '10.0.0.1'): void
    {
        for ($i = 0; $i < $vezes; $i++) {
            $this->pedido('POST', $this->url('/plataforma/login'), ['email' => $email, 'password' => 'errada-Errada-1!'], ip: $ip);
        }
    }

    // --- Login -------------------------------------------------------------------------------

    public function test_o_guard_por_omissao_continua_web(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
    }

    public function test_a_ordem_efectiva_do_middleware_das_rotas_autenticadas(): void
    {
        $rota = Route::getRoutes()->getByName('plataforma.inicio');
        $efectivo = array_map(
            fn ($m) => is_string($m) ? class_basename(explode(':', $m)[0]) : 'closure',
            app('router')->gatherRouteMiddleware($rota),
        );

        $posicao = fn (string $nome) => array_search($nome, $efectivo, true);
        $esperada = ['ApenasHostCentral', 'ConfigurarSessaoPlataforma', 'EncryptCookies', 'StartSession', 'ValidateCsrfToken', 'Authenticate', 'SuperAdminActivo', 'ExigirTrocaDeSenhaPlataforma'];

        foreach ($esperada as $nome) {
            $this->assertNotFalse($posicao($nome), "{$nome} em falta: " . implode(', ', $efectivo));
        }
        $ordenada = $esperada;
        usort($ordenada, fn ($a, $b) => $posicao($a) <=> $posicao($b));
        $this->assertSame($esperada, $ordenada, 'Ordem efectiva: ' . implode(', ', $efectivo));
        $this->assertSame('ApenasHostCentral', $efectivo[0]);
    }

    public function test_visitante_vai_para_o_login_do_painel(): void
    {
        $this->pedido('GET', $this->url('/plataforma'))->assertRedirect($this->url('/plataforma/login'));
        $this->pedido('GET', $this->url('/plataforma/alterar-senha'))->assertRedirect($this->url('/plataforma/login'));
        $this->pedido('POST', $this->url('/plataforma/logout'))->assertRedirect($this->url('/plataforma/login'));

        // Controlo positivo: o login é público.
        $this->pedido('GET', $this->url('/plataforma/login'))->assertOk();
    }

    public function test_login_certo_entra_e_regenera_a_sessao(): void
    {
        $admin = $this->admin();

        $antes = $this->pedido('GET', $this->url('/plataforma/login'));
        $idAnterior = $this->idDaSessao($antes, $this->nomeDoCookie());

        $resposta = $this->pedido('POST', $this->url('/plataforma/login'), ['email' => 'RUI@plataforma.test ', 'password' => self::SENHA], [$this->nomeDoCookie() => $idAnterior]);

        $resposta->assertRedirect($this->url('/plataforma'));
        $this->assertAuthenticatedAs($admin, 'plataforma');
        $idNovo = $this->idDaSessao($resposta, $this->nomeDoCookie());
        $this->assertNotSame($idAnterior, $idNovo, 'A sessão tem de ser regenerada no login.');
        $this->assertNotNull($admin->fresh()->ultimo_login_em);

        // O id antigo (fixação de sessão) não dá acesso; o novo sim.
        $this->noPainel('GET', '/plataforma', $idAnterior)->assertRedirect($this->url('/plataforma/login'));
        $this->noPainel('GET', '/plataforma/_auth/leitura', $idNovo)->assertOk();
    }

    public function test_a_pagina_inicial_do_painel_e_inertia_com_o_super_admin(): void
    {
        $sessao = $this->entrar($this->admin('outro@plataforma.test'));

        // `/plataforma` redirecciona para a listagem de escolas (Task 4).
        $this->noPainel('GET', '/plataforma', $sessao)->assertRedirect($this->url('/plataforma/escolas'));
        $resposta = $this->noPainel('GET', '/plataforma/escolas', $sessao, cabecalhos: $this->inertia());

        $resposta->assertOk();
        $this->assertSame('Plataforma/Escolas/Index', $resposta->json('component'));
        $this->assertSame('outro@plataforma.test', $resposta->json('props.auth.superAdmin.email'));
    }

    public function test_um_visitante_autenticado_no_login_e_enviado_para_o_inicio(): void
    {
        $sessao = $this->entrar($this->admin());

        $this->noPainel('GET', '/plataforma/login', $sessao)->assertRedirect($this->url('/plataforma'));
    }

    public static function logins_recusados(): array
    {
        return [
            'senha errada' => ['rui@plataforma.test', 'errada-Errada-1!', true],
            'email inexistente' => ['ninguem@plataforma.test', 'Senha-Muito-Segura-1!', true],
            'conta desactivada com a senha certa' => ['rui@plataforma.test', 'Senha-Muito-Segura-1!', false],
        ];
    }

    #[DataProvider('logins_recusados')]
    public function test_login_recusado_da_sempre_a_mesma_mensagem_generica(string $email, string $senha, bool $activo): void
    {
        $this->admin(activo: $activo);

        $resposta = $this->pedido('POST', $this->url('/plataforma/login'), ['email' => $email, 'password' => $senha]);

        $resposta->assertRedirect($this->url('/plataforma/login'));
        $this->assertSame(self::MENSAGEM_GENERICA, session('errors')->first('email'));
        $this->assertSame(['email'], array_keys(session('errors')->getBag('default')->messages()), 'Só um erro, no campo email.');
        $this->assertGuest('plataforma');
        $this->assertNull(SuperAdmin::where('email', 'rui@plataforma.test')->first()->ultimo_login_em);
        // O e-mail volta ao formulário, a senha nunca.
        $this->assertSame($email, session()->getOldInput('email'));
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_as_tres_recusas_sao_indistinguiveis(): void
    {
        $this->admin('activa@plataforma.test');
        $this->admin('inactiva@plataforma.test', activo: false);

        $respostas = [];
        foreach ([['activa@plataforma.test', 'errada-Errada-1!'], ['nao-existe@plataforma.test', 'errada-Errada-1!'], ['inactiva@plataforma.test', self::SENHA]] as $i => [$email, $senha]) {
            $r = $this->pedido('POST', $this->url('/plataforma/login'), ['email' => $email, 'password' => $senha], ip: "10.0.1.{$i}");
            $respostas[] = [$r->getStatusCode(), $r->headers->get('Location'), session('errors')->first('email')];
        }

        $this->assertCount(1, array_unique(array_map('serialize', $respostas)));
    }

    public function test_a_validacao_do_formulario_de_login(): void
    {
        $this->pedido('POST', $this->url('/plataforma/login'), ['email' => '', 'password' => ''])
            ->assertSessionHasErrors(['email', 'password']);
        $this->pedido('POST', $this->url('/plataforma/login'), ['email' => 'nao-e-email', 'password' => 'x'])
            ->assertSessionHasErrors(['email']);
        $this->pedido('POST', $this->url('/plataforma/login'), ['email' => ['a'], 'password' => ['b']])
            ->assertSessionHasErrors(['email', 'password']);
    }

    // --- Limitador ---------------------------------------------------------------------------

    public function test_cinco_falhas_do_mesmo_email_e_ip_bloqueiam_ate_com_a_senha_certa(): void
    {
        $admin = $this->admin();

        $this->falhar($admin->email, 5);
        $bloqueado = $this->pedido('POST', $this->url('/plataforma/login'), ['email' => $admin->email, 'password' => self::SENHA]);

        $bloqueado->assertRedirect($this->url('/plataforma/login'));
        $this->assertStringContainsString('Muitas tentativas', session('errors')->first('email'));
        $this->assertGuest('plataforma');

        // Controlo positivo: outro IP (balde próprio) entra com a mesma conta.
        $this->entrar($admin, ip: '10.0.0.2');
    }

    public function test_quatro_falhas_ainda_deixam_entrar(): void
    {
        $admin = $this->admin();

        $this->falhar($admin->email, 4);

        $this->entrar($admin);
    }

    public function test_so_as_falhas_contam(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 8; $i++) {
            $this->entrar($admin);
        }
    }

    public function test_trinta_falhas_do_mesmo_ip_bloqueiam_o_ip_mesmo_com_contas_diferentes(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 29; $i++) {
            $this->falhar("alvo{$i}@plataforma.test", 1);
        }
        // 29 falhas: ainda entra (controlo positivo; um sucesso não gasta quota do IP).
        $this->entrar($admin);

        $this->falhar('alvo-final@plataforma.test', 1);
        $this->pedido('POST', $this->url('/plataforma/login'), ['email' => $admin->email, 'password' => self::SENHA])
            ->assertRedirect($this->url('/plataforma/login'));
        $this->assertStringContainsString('Muitas tentativas', session('errors')->first('email'));

        // Outro IP não é afectado.
        $this->entrar($admin, ip: '10.9.9.9');
    }

    public function test_o_limitador_usa_chaves_com_prefixo_proprio_sem_tenant(): void
    {
        $limites = RateLimiter::limiter('plataforma.login')(Request::create('/plataforma/login', 'POST', ['email' => 'Rui@Plataforma.test'], server: ['REMOTE_ADDR' => '10.0.0.1']));

        $this->assertCount(2, $limites, 'E-mail+IP e IP.');
        foreach ($limites as $limite) {
            $this->assertStringStartsWith('p|', $limite->key);
        }
        $this->assertNotSame($limites[0]->key, $limites[1]->key);
        $this->assertStringNotContainsString('rui@plataforma.test', $limites[0]->key, 'O e-mail não vai em claro para a cache.');
    }

    public function test_falhas_na_plataforma_nao_bloqueiam_a_escola(): void
    {
        $admin = $this->admin();
        $escola = $this->utilizadorDeEscola($admin->email);

        $this->falhar($admin->email, 5);
        $this->pedido('POST', $this->url('/plataforma/login'), ['email' => $admin->email, 'password' => self::SENHA])
            ->assertSessionHasErrors('email');

        // Mesmo e-mail, mesmo IP: a escola entra.
        $this->entrarNaEscola($escola);
        $this->assertTrue(Auth::guard('web')->check());
    }

    public function test_falhas_na_escola_nao_bloqueiam_a_plataforma(): void
    {
        $admin = $this->admin();
        $this->utilizadorDeEscola($admin->email);

        for ($i = 0; $i < 6; $i++) {
            $this->pedido('POST', self::ESCOLA . '/login', ['login' => $admin->email, 'password' => 'errada']);
        }
        // Controlo positivo: a escola ficou mesmo bloqueada.
        $this->pedido('POST', self::ESCOLA . '/login', ['login' => $admin->email, 'password' => 'password123'])
            ->assertSessionHasErrors('login');

        $this->entrar($admin);
    }

    // --- Troca obrigatória de senha ----------------------------------------------------------

    public function test_com_a_flag_so_a_troca_o_seu_put_e_o_logout_passam(): void
    {
        $admin = $this->admin(deveAlterar: true);
        $sessao = $this->entrar($admin);
        $troca = $this->url('/plataforma/alterar-senha');

        $this->noPainel('GET', '/plataforma', $sessao)->assertRedirect($troca);
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao)->assertRedirect($troca);
        $this->noPainel('POST', '/plataforma/_auth/escrita', $sessao)->assertRedirect($troca);
        $this->assertSame(0, $this->efeitos, 'A escrita bloqueada não pode ter executado.');

        // Pedido de dados (JSON) fora da allowlist: 403 com mensagem.
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao, cabecalhos: ['Accept' => 'application/json'])->assertForbidden();

        // Inertia: navegação completa (a vista de raiz é outra), nunca um redireccionamento SPA.
        $this->noPainel('GET', '/plataforma', $sessao, cabecalhos: $this->inertia())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $troca);

        // Allowlist.
        $this->noPainel('GET', '/plataforma/alterar-senha', $sessao)->assertOk();
        $this->noPainel('PUT', '/plataforma/alterar-senha', $sessao, ['current_password' => 'errada'])
            ->assertSessionHasErrors('current_password');
        $this->noPainel('POST', '/plataforma/logout', $sessao)->assertRedirect($this->url('/plataforma/login'));
    }

    public function test_sem_a_flag_as_rotas_autenticadas_passam(): void
    {
        $sessao = $this->entrar($this->admin());

        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao)->assertOk()->assertSee('leitura-ok');
        $this->noPainel('POST', '/plataforma/_auth/escrita', $sessao)->assertOk();
        $this->assertSame(1, $this->efeitos);
    }

    public function test_o_logout_inertia_com_a_flag_termina_a_sessao(): void
    {
        $sessao = $this->entrar($this->admin(deveAlterar: true));

        $this->noPainel('POST', '/plataforma/logout', $sessao, cabecalhos: $this->inertia())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $this->url('/plataforma/login'));
        $this->assertGuest('plataforma');
    }

    // --- Troca própria de senha --------------------------------------------------------------

    public static function trocas_invalidas(): array
    {
        return [
            'senha actual errada' => [['current_password' => 'Errada-Errada-1!', 'password' => self::NOVA, 'password_confirmation' => self::NOVA], 'current_password'],
            'senha actual em falta' => [['password' => self::NOVA, 'password_confirmation' => self::NOVA], 'current_password'],
            'menos de 12 caracteres' => [['current_password' => self::SENHA, 'password' => 'Abc!12345', 'password_confirmation' => 'Abc!12345'], 'password'],
            'onze caracteres com mistura' => [['current_password' => self::SENHA, 'password' => 'Abcdefg!123', 'password_confirmation' => 'Abcdefg!123'], 'password'],
            'sem maiúsculas' => [['current_password' => self::SENHA, 'password' => 'senha-muito-nova-1!', 'password_confirmation' => 'senha-muito-nova-1!'], 'password'],
            'sem minúsculas' => [['current_password' => self::SENHA, 'password' => 'SENHA-MUITO-NOVA-1!', 'password_confirmation' => 'SENHA-MUITO-NOVA-1!'], 'password'],
            'sem números' => [['current_password' => self::SENHA, 'password' => 'Senha-Muito-Nova-!', 'password_confirmation' => 'Senha-Muito-Nova-!'], 'password'],
            'sem símbolos' => [['current_password' => self::SENHA, 'password' => 'SenhaMuitoNova123', 'password_confirmation' => 'SenhaMuitoNova123'], 'password'],
            'confirmação diferente' => [['current_password' => self::SENHA, 'password' => self::NOVA, 'password_confirmation' => self::NOVA . 'x'], 'password'],
            'igual à actual' => [['current_password' => self::SENHA, 'password' => self::SENHA, 'password_confirmation' => self::SENHA], 'password'],
        ];
    }

    #[DataProvider('trocas_invalidas')]
    public function test_troca_invalida_e_recusada_sem_alterar_nada(array $dados, string $campo): void
    {
        $admin = $this->admin(deveAlterar: true);
        $hash = $admin->password;
        $sessao = $this->entrar($admin);

        $this->noPainel('PUT', '/plataforma/alterar-senha', $sessao, $dados)->assertSessionHasErrors($campo);

        $admin->refresh();
        $this->assertSame($hash, $admin->password);
        $this->assertTrue($admin->deve_alterar_senha);
    }

    public function test_a_mensagem_de_igual_a_actual_e_a_da_regra_different(): void
    {
        $sessao = $this->entrar($this->admin(deveAlterar: true));

        $this->noPainel('PUT', '/plataforma/alterar-senha', $sessao, ['current_password' => self::SENHA, 'password' => self::SENHA, 'password_confirmation' => self::SENHA]);

        $this->assertSame('A nova senha tem de ser diferente da senha actual.', session('errors')->first('password'));
    }

    public function test_troca_valida_limpa_a_flag_roda_o_remember_token_e_mantem_a_sessao_actual(): void
    {
        $admin = $this->admin(deveAlterar: true);
        $admin->forceFill(['remember_token' => 'token-antigo'])->save();
        $hashAntigo = $admin->password;
        $sessao = $this->entrar($admin);

        $resposta = $this->noPainel('PUT', '/plataforma/alterar-senha', $sessao, ['current_password' => self::SENHA, 'password' => self::NOVA, 'password_confirmation' => self::NOVA]);

        $resposta->assertRedirect($this->url('/plataforma'));
        $admin->refresh();
        $this->assertFalse($admin->deve_alterar_senha);
        $this->assertNotSame($hashAntigo, $admin->password);
        $this->assertTrue(Hash::check(self::NOVA, $admin->password));
        $this->assertNotSame('token-antigo', $admin->remember_token);
        $this->assertNotNull($admin->remember_token);

        // A sessão actual mantém o acesso e a rota que antes estava bloqueada abre.
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao)->assertOk();
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao)->assertOk();

        // A senha temporária deixou de servir; a nova serve.
        $this->pedido('POST', $this->url('/plataforma/login'), ['email' => $admin->email, 'password' => self::SENHA])->assertSessionHasErrors('email');
        $this->entrar($admin, self::NOVA, ip: '10.0.0.5');
    }

    public function test_a_troca_invalida_as_outras_sessoes_do_super_admin_e_mantem_a_actual(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin(deveAlterar: true);
        $sessaoA = $this->entrar($admin);
        $sessaoB = $this->entrar($admin, ip: '10.0.0.2');
        $this->assertNotSame($sessaoA, $sessaoB);
        $this->assertDatabaseHas('sessions', ['id' => $sessaoB]);

        $this->noPainel('PUT', '/plataforma/alterar-senha', $sessaoA, ['current_password' => self::SENHA, 'password' => self::NOVA, 'password_confirmation' => self::NOVA])
            ->assertRedirect($this->url('/plataforma'));

        // A outra sessão é expulsa no pedido seguinte e termina (a linha desaparece).
        $this->noPainel('GET', '/plataforma', $sessaoB)->assertRedirect($this->url('/plataforma/login'));
        $this->assertGuest('plataforma');
        $this->assertDatabaseMissing('sessions', ['id' => $sessaoB]);
        // Reabrir a sessão antiga não dá acesso.
        $this->noPainel('GET', '/plataforma', $sessaoB)->assertRedirect($this->url('/plataforma/login'));

        // A actual continua.
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessaoA)->assertOk();
        $this->assertDatabaseHas('sessions', ['id' => $sessaoA]);
    }

    public function test_a_troca_de_um_super_admin_nao_afecta_as_sessoes_de_outro(): void
    {
        $a = $this->admin('a@plataforma.test');
        $b = $this->admin('b@plataforma.test');
        $sessaoDeB = $this->entrar($b);
        $sessaoDeA = $this->entrar($a);

        $this->noPainel('PUT', '/plataforma/alterar-senha', $sessaoDeA, ['current_password' => self::SENHA, 'password' => self::NOVA, 'password_confirmation' => self::NOVA])->assertRedirect();

        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessaoDeB)->assertOk();
    }

    public function test_reset_por_comando_invalida_as_sessoes_abertas(): void
    {
        $admin = $this->admin();
        $sessao = $this->entrar($admin);
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao)->assertOk();

        $this->assertSame(0, Artisan::call('mosi:plataforma:admin:reset', ['--email' => $admin->email]));

        $this->noPainel('GET', '/plataforma', $sessao)->assertRedirect($this->url('/plataforma/login'));
        $this->assertGuest('plataforma');
    }

    public function test_um_super_admin_desactivado_com_sessao_aberta_e_expulso_no_pedido_seguinte(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $sessao = $this->entrar($admin);
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao)->assertOk();

        $admin->update(['estado' => 0]);

        $this->noPainel('GET', '/plataforma', $sessao)->assertRedirect($this->url('/plataforma/login'));
        $this->assertGuest('plataforma');
        $this->assertDatabaseMissing('sessions', ['id' => $sessao]);

        // Reactivar a conta não ressuscita a sessão terminada.
        $admin->update(['estado' => 1]);
        $this->noPainel('GET', '/plataforma', $sessao)->assertRedirect($this->url('/plataforma/login'));
    }

    public function test_a_expulsao_de_um_desactivado_em_pedido_inertia_usa_navegacao_completa(): void
    {
        $admin = $this->admin();
        $sessao = $this->entrar($admin);
        $admin->update(['estado' => 0]);

        $this->noPainel('GET', '/plataforma', $sessao, cabecalhos: $this->inertia())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $this->url('/plataforma/login'));
    }

    public function test_um_super_admin_apagado_com_sessao_aberta_e_expulso(): void
    {
        $admin = $this->admin();
        $sessao = $this->entrar($admin);
        $admin->delete();

        $this->noPainel('GET', '/plataforma', $sessao)->assertRedirect($this->url('/plataforma/login'));
    }

    // --- Dois mundos -------------------------------------------------------------------------

    public function test_um_utilizador_de_escola_nao_passa_em_auth_plataforma(): void
    {
        $user = $this->utilizadorDeEscola();
        $sessaoEscola = $this->entrarNaEscola($user);
        $nomeEscola = (string) config('session.cookie');

        // O cookie da escola, enviado ao painel, não autentica...
        $this->pedido('GET', $this->url('/plataforma'), [], [$nomeEscola => $sessaoEscola])->assertRedirect($this->url('/plataforma/login'));
        // ... e nem com o mesmo id de sessão sob o nome do cookie do painel.
        $this->noPainel('GET', '/plataforma', $sessaoEscola)->assertRedirect($this->url('/plataforma/login'));
        // Controlo positivo: na escola esse cookie ainda autentica.
        $this->naEscola('/alterar-senha', $sessaoEscola)->assertOk();

        // Mesmo dentro da MESMA sessão (Store partilhado, sem flush): o guard `web` não vale para `plataforma`.
        Auth::guard('web')->login($user);
        $this->assertTrue(Auth::guard('web')->check());
        $this->pedido('GET', $this->url('/plataforma'), processoNovo: false)->assertRedirect($this->url('/plataforma/login'));
        $this->assertFalse(Auth::guard('plataforma')->check());
    }

    public function test_um_super_admin_nao_passa_na_escola(): void
    {
        $admin = $this->admin();
        $sessao = $this->entrar($admin);
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao)->assertOk();

        // Sessão do painel enviada à escola, sob os dois nomes de cookie.
        $this->naEscola('/alterar-senha', $sessao)->assertRedirect(self::ESCOLA . '/login');
        $this->pedido('GET', self::ESCOLA . '/alterar-senha', [], [$this->nomeDoCookie() => $sessao])->assertRedirect(self::ESCOLA . '/login');

        // Mesma sessão (Store partilhado, sem flush) logo depois de um login real do Super Admin.
        $this->entrar($admin);
        $this->assertTrue(Auth::guard('plataforma')->check());
        $this->pedido('GET', self::ESCOLA . '/alterar-senha', processoNovo: false)->assertRedirect(self::ESCOLA . '/login');
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_sessions_user_id_fica_nulo_depois_do_login_e_a_revogacao_da_escola_nao_a_apaga(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $this->utilizadorDeEscola('escola@plataforma.test');

        $sessao = $this->entrar($admin);

        $linha = DB::table('sessions')->where('id', $sessao)->first();
        $this->assertNotNull($linha);
        $this->assertNull($linha->user_id, 'O guard por omissão é web: a sessão da Plataforma não pode ter user_id.');
        $this->assertStringContainsString('login_plataforma_', base64_decode($linha->payload), 'Controlo positivo: a sessão está mesmo autenticada.');

        // Também depois de pedidos AUTENTICADOS (`auth:plataforma` muda o guard por omissão durante o pedido).
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao)->assertOk();
        $this->noPainel('GET', '/plataforma/alterar-senha', $sessao)->assertOk();
        $this->assertNull(DB::table('sessions')->where('id', $sessao)->value('user_id'));
        $this->assertSame('web', config('auth.defaults.guard'), 'O guard por omissão não pode ficar alterado depois do pedido.');

        $this->noTenant($this->tenant, fn () => app(RevogarAcessosDoTenantAction::class)->revogar());

        $this->assertDatabaseHas('sessions', ['id' => $sessao]);
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessao)->assertOk();
    }

    public function test_a_sessao_de_escola_com_o_mesmo_id_numerico_nao_e_afectada_e_vice_versa(): void
    {
        config(['session.driver' => 'database']);
        $escola = $this->utilizadorDeEscola('escola@plataforma.test');
        $admin = $this->admin('rui@plataforma.test');
        $this->assertSame($escola->id, $admin->id, 'Pré-condição: ids numéricos iguais.');

        $sessaoEscola = $this->entrarNaEscola($escola);
        $linhaEscola = ['id' => $sessaoEscola, 'user_id' => $escola->id];
        $this->assertDatabaseHas('sessions', $linhaEscola);

        $intacta = function () use ($sessaoEscola, $linhaEscola): void {
            $this->assertDatabaseHas('sessions', $linhaEscola);
            $this->naEscola('/alterar-senha', $sessaoEscola)->assertOk();
        };

        // 1. Troca própria de senha do Super Admin.
        $sessao = $this->entrar($admin);
        $this->noPainel('PUT', '/plataforma/alterar-senha', $sessao, ['current_password' => self::SENHA, 'password' => self::NOVA, 'password_confirmation' => self::NOVA])->assertRedirect();
        $intacta();

        // 2. Reset por comando (e senha conhecida de seguida: é a BD de teste).
        Artisan::call('mosi:plataforma:admin:reset', ['--email' => $admin->email]);
        $this->noPainel('GET', '/plataforma', $sessao)->assertRedirect($this->url('/plataforma/login'));
        $admin->refresh()->forceFill(['password' => Hash::make(self::SENHA), 'deve_alterar_senha' => false])->save();
        $intacta();

        // 3. Desactivação com sessão aberta.
        $sessao = $this->entrar($admin);
        $admin->update(['estado' => 0]);
        $this->noPainel('GET', '/plataforma', $sessao)->assertRedirect($this->url('/plataforma/login'));
        $admin->update(['estado' => 1]);
        $intacta();

        // 4. Logout.
        $sessao = $this->entrar($admin);
        $this->noPainel('POST', '/plataforma/logout', $sessao)->assertRedirect();
        $intacta();

        // Sentido contrário: a troca de senha de ESCOLA apaga as suas outras sessões por user_id,
        // mas nunca a sessão da Plataforma (user_id nulo).
        $sessaoPlataforma = $this->entrar($admin);
        $outraSessaoEscola = $this->entrarNaEscola($escola, ip: '10.0.0.7');
        $this->assertDatabaseHas('sessions', ['id' => $outraSessaoEscola, 'user_id' => $escola->id]);

        $this->noTenant($this->tenant, fn () => app(AlterarPropriaSenhaAction::class)->executar($escola->fresh(), 'Nova-Escola-123!', $sessaoEscola));

        $this->assertDatabaseMissing('sessions', ['id' => $outraSessaoEscola]);
        $this->assertDatabaseHas('sessions', ['id' => $sessaoPlataforma]);
        $this->noPainel('GET', '/plataforma/_auth/leitura', $sessaoPlataforma)->assertOk();
    }

    public function test_o_primeiro_pedido_ao_painel_usa_os_minutos_da_plataforma_no_handler_de_base_de_dados(): void
    {
        config(['session.driver' => 'database', 'session.lifetime' => 120, 'plataforma.sessao_minutos' => 15]);

        $this->pedido('GET', $this->url('/plataforma/login'))->assertOk();

        $handler = app('session')->driver()->getHandler();
        $minutos = new ReflectionProperty($handler, 'minutes');
        $this->assertSame(15, $minutos->getValue($handler), 'Uma sessão do painel expira aos minutos da Plataforma, não aos da escola.');
    }

    // --- Sem registo público -----------------------------------------------------------------

    public function test_nao_ha_registo_nem_recuperacao_de_senha_por_email(): void
    {
        foreach (['/plataforma/register', '/plataforma/forgot-password', '/plataforma/reset-password', '/plataforma/reset-password/token', '/plataforma/two-factor-challenge'] as $caminho) {
            $this->pedido('GET', $this->url($caminho))->assertNotFound();
            $this->pedido('POST', $this->url($caminho))->assertNotFound();
        }

        foreach (['plataforma.register', 'plataforma.password.request', 'plataforma.password.email', 'plataforma.password.reset'] as $nome) {
            $this->assertFalse(Route::has($nome), $nome);
        }

        // Controlo positivo.
        $this->pedido('GET', $this->url('/plataforma/login'))->assertOk();
    }

    // --- CSRF --------------------------------------------------------------------------------

    public function test_o_token_csrf_do_painel_e_partilhado_como_prop(): void
    {
        $resposta = $this->pedido('GET', $this->url('/plataforma/login'), cabecalhos: $this->inertia());

        $resposta->assertOk();
        $this->assertSame('Plataforma/Login', $resposta->json('component'));
        $token = $resposta->json('props.csrf_token');
        $this->assertIsString($token);
        $this->assertSame(session()->token(), $token);
        $this->assertSame(40, strlen($token));
    }

    public function test_csrf_real_o_x_csrf_token_do_painel_vence_o_xsrf_token_da_escola(): void
    {
        $this->activarCsrfReal();
        $admin = $this->admin();

        // Token do painel (prop partilhada) e cookie de sessão do painel.
        $login = $this->pedido('GET', $this->url('/plataforma/login'), cabecalhos: $this->inertia());
        $tokenDoPainel = $login->json('props.csrf_token');
        $sessao = $this->idDaSessao($login, $this->nomeDoCookie());

        // O cookie XSRF-TOKEN da escola (de domínio-pai em dev) chega ao host do painel e o cliente HTTP
        // do Inertia envia-o como X-XSRF-TOKEN: o valor é válido para a escola, mas não para o painel.
        $daEscola = $this->pedido('GET', self::ESCOLA . '/login');
        $xsrfDaEscola = $daEscola->getCookie('XSRF-TOKEN', false)?->getValue();
        $this->assertNotEmpty($xsrfDaEscola, 'Pré-condição: a escola emite o cookie XSRF-TOKEN.');

        $dados = ['email' => $admin->email, 'password' => self::SENHA];
        $cookies = [$this->nomeDoCookie() => $sessao];

        // Só o cookie/cabeçalho errado: 419.
        $this->pedido('POST', $this->url('/plataforma/login'), $dados, $cookies, ['X-XSRF-TOKEN' => $xsrfDaEscola])->assertStatus(419);
        // Sem nenhum token: 419.
        $this->pedido('POST', $this->url('/plataforma/login'), $dados, $cookies)->assertStatus(419);
        // X-CSRF-TOKEN errado, mesmo com o XSRF "certo" da escola: 419 (o X-CSRF-TOKEN tem precedência).
        $this->pedido('POST', $this->url('/plataforma/login'), $dados, $cookies, ['X-CSRF-TOKEN' => 'errado', 'X-XSRF-TOKEN' => $xsrfDaEscola])->assertStatus(419);
        $this->assertGuest('plataforma');

        // X-CSRF-TOKEN do painel + XSRF-TOKEN errado da escola: passa.
        $this->pedido('POST', $this->url('/plataforma/login'), $dados, $cookies + ['XSRF-TOKEN' => 'lixo'], ['X-CSRF-TOKEN' => $tokenDoPainel, 'X-XSRF-TOKEN' => $xsrfDaEscola])
            ->assertRedirect($this->url('/plataforma'));
        $this->assertAuthenticatedAs($admin, 'plataforma');
    }

    public function test_csrf_real_protege_tambem_a_troca_de_senha_e_o_logout(): void
    {
        $sessao = $this->entrar($this->admin(), self::SENHA);
        $this->activarCsrfReal();

        $this->noPainel('PUT', '/plataforma/alterar-senha', $sessao, ['current_password' => self::SENHA, 'password' => self::NOVA, 'password_confirmation' => self::NOVA])->assertStatus(419);
        $this->noPainel('POST', '/plataforma/logout', $sessao)->assertStatus(419);
    }

    // --- Logout ------------------------------------------------------------------------------

    public function test_logout_invalida_a_sessao_e_regenera_o_token(): void
    {
        config(['session.driver' => 'database']);
        $sessao = $this->entrar($this->admin());
        $token = $this->noPainel('GET', '/plataforma/escolas', $sessao, cabecalhos: $this->inertia())->json('props.csrf_token');
        $this->assertNotEmpty($token);

        $resposta = $this->noPainel('POST', '/plataforma/logout', $sessao);

        $resposta->assertRedirect($this->url('/plataforma/login'));
        $this->assertGuest('plataforma');
        $this->assertNotSame($token, session()->token(), 'O token CSRF tem de ser regenerado.');
        $this->assertDatabaseMissing('sessions', ['id' => $sessao]);
        $this->assertNotSame($sessao, $this->idDaSessao($resposta, $this->nomeDoCookie()));
        $this->noPainel('GET', '/plataforma', $sessao)->assertRedirect($this->url('/plataforma/login'));
    }

    public function test_logout_inertia_usa_navegacao_completa(): void
    {
        $sessao = $this->entrar($this->admin());

        $this->noPainel('POST', '/plataforma/logout', $sessao, cabecalhos: $this->inertia())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $this->url('/plataforma/login'));
        $this->assertGuest('plataforma');
    }

    // --- Cabeçalhos e contexto ---------------------------------------------------------------

    public function test_todas_as_respostas_do_painel_levam_no_store(): void
    {
        $admin = $this->admin();
        $marcada = $this->admin('marcada@plataforma.test', deveAlterar: true);

        $respostas = [];
        $respostas['login GET'] = $this->pedido('GET', $this->url('/plataforma/login'));
        $respostas['login falhado'] = $this->pedido('POST', $this->url('/plataforma/login'), ['email' => $admin->email, 'password' => 'x']);
        $respostas['visitante'] = $this->pedido('GET', $this->url('/plataforma'));

        $sessao = $this->entrar($admin);
        $respostas['inicio'] = $this->noPainel('GET', '/plataforma', $sessao);
        $respostas['escolas'] = $this->noPainel('GET', '/plataforma/escolas', $sessao);
        $respostas['escolas inertia'] = $this->noPainel('GET', '/plataforma/escolas', $sessao, cabecalhos: $this->inertia());
        $respostas['senha GET'] = $this->noPainel('GET', '/plataforma/alterar-senha', $sessao);
        $respostas['senha PUT invalido'] = $this->noPainel('PUT', '/plataforma/alterar-senha', $sessao, ['current_password' => 'x']);
        $respostas['logout'] = $this->noPainel('POST', '/plataforma/logout', $sessao);

        $sessaoMarcada = $this->entrar($marcada, ip: '10.0.0.9');
        $respostas['bloqueado pela troca'] = $this->noPainel('GET', '/plataforma', $sessaoMarcada);
        $respostas['bloqueado json'] = $this->noPainel('GET', '/plataforma/_auth/leitura', $sessaoMarcada, cabecalhos: ['Accept' => 'application/json']);
        $respostas['bloqueado inertia'] = $this->noPainel('GET', '/plataforma', $sessaoMarcada, cabecalhos: $this->inertia());

        $this->activarCsrfReal();
        $respostas['419'] = $this->pedido('POST', $this->url('/plataforma/login'), ['email' => 'a@b.c', 'password' => 'x']);
        $this->assertSame(419, $respostas['419']->getStatusCode());

        foreach ($respostas as $nome => $resposta) {
            $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'), "Cache-Control em '{$nome}'");
            $this->assertSame('noindex', $resposta->headers->get('X-Robots-Tag'), "X-Robots-Tag em '{$nome}'");
        }
    }

    public function test_o_contexto_de_tenant_continua_vazio_em_todo_o_fluxo(): void
    {
        app('router')->pushMiddlewareToGroup('plataforma', EspiaDeContexto::class);
        EspiaDeContexto::$observacoes = [];
        $admin = $this->admin(deveAlterar: true);

        $this->pedido('GET', $this->url('/plataforma/login'));
        $this->falhar($admin->email, 1);
        $sessao = $this->entrar($admin);
        $this->noPainel('GET', '/plataforma', $sessao);
        $this->noPainel('PUT', '/plataforma/alterar-senha', $sessao, ['current_password' => self::SENHA, 'password' => self::NOVA, 'password_confirmation' => self::NOVA]);
        $this->noPainel('GET', '/plataforma', $sessao);
        // A listagem de escolas (Task 4) é uma rota real do painel que lê tenants sem contexto.
        $this->noPainel('GET', '/plataforma/escolas', $sessao);
        $this->noPainel('POST', '/plataforma/logout', $sessao);

        // (O primeiro GET do início, com a troca obrigatória pendente, é recusado antes de chegar ao espião,
        // que está no fim do grupo: a verificação de conta corre antes de resolver `{tenant}`.)
        $this->assertGreaterThanOrEqual(7, count(EspiaDeContexto::$observacoes));
        $this->assertSame([], array_filter(EspiaDeContexto::$observacoes), 'Algum pedido do painel viu um tenant em contexto.');
        // (o helper `pedido` já afirma temTenant() === false depois de cada pedido ao painel.)
    }
}
