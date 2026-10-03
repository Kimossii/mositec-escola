<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Testing\TestResponse;
use Modules\Autenticacao\Actions\RevogarAcessosDoTenantAction;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Plataforma\Http\Middleware\HandleInertiaPlataforma;
use Modules\Tenant\Models\Tenant;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Fixtures\Plataforma\EspiaDeContexto;
use Tests\Fixtures\Plataforma\LeituraTenantScopedDeTeste;
use Tests\TestCase;

/**
 * A fronteira entre os dois mundos: o host central serve só a Plataforma (sem tenant) e o host
 * de um tenant serve só a escola. Cada teste tem controlo positivo e negativo.
 */
class FronteiraTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL = 'painel.mositec.test';

    private const ESCOLA = 'escola.mositec.test';

    private Tenant $escola;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.hosts_centrais' => [self::CENTRAL]]);
        $this->escola = $this->criarTenant('MOSI-000010', 'Escola da Fronteira', self::ESCOLA);
        EspiaDeContexto::$observacoes = [];

        // Fixtures: só existem nestes testes.
        Route::middleware('plataforma')->get('/plataforma/_fronteira/so-plataforma', fn () => 'so-plataforma');
        Route::middleware('plataforma')->get('/plataforma/_fronteira/leitura', LeituraTenantScopedDeTeste::class);
        Route::middleware('web')->get('/_fronteira/leitura-escola', LeituraTenantScopedDeTeste::class);
    }

    private function central(string $caminho = '/'): string
    {
        return 'http://' . self::CENTRAL . $caminho;
    }

    private function escola(string $caminho = '/'): string
    {
        return 'http://' . self::ESCOLA . $caminho;
    }

    private function semContexto(): void
    {
        app(TenantContext::class)->limpar();
    }

    // --- Host e mundos -----------------------------------------------------------------------

    public function test_host_central_serve_a_plataforma_e_nao_a_escola(): void
    {
        $this->semContexto();

        // Plataforma: 200, com o grupo `plataforma` (cabeçalhos próprios) e sem tenant.
        $this->get($this->central('/plataforma/login'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex');
        $this->assertFalse(app(TenantContext::class)->temTenant());

        // Escola: nada responde no host central, em nenhum dos grupos.
        $this->get($this->central('/cursos'))->assertNotFound();
        $this->get($this->central('/login'))->assertNotFound();
        $this->getJson($this->central('/api/v1/turmas'))->assertNotFound();
        $this->get($this->central('/_fronteira/leitura-escola'))->assertNotFound();

        // Controlo positivo: a mesma rota da escola responde no host da escola.
        $this->get($this->escola('/_fronteira/leitura-escola'))->assertOk()->assertSee('cursos:0');
        $this->get($this->escola('/login'))->assertOk();
    }

    public function test_host_de_tenant_serve_a_escola_e_nao_a_plataforma(): void
    {
        // Rota só da Plataforma: 404 num host de escola; 200 no central (controlo positivo).
        $this->get($this->escola('/plataforma/_fronteira/so-plataforma'))->assertNotFound();
        $this->get($this->central('/plataforma/_fronteira/so-plataforma'))->assertOk()->assertSee('so-plataforma');

        // As rotas reais da Plataforma não existem no host da escola; a `/` da escola continua a funcionar.
        $this->get($this->escola('/plataforma'))->assertNotFound();
        $resposta = $this->get($this->escola('/'));
        $resposta->assertRedirect('/login');
        $this->assertStringNotContainsString('plataforma-ok', (string) $resposta->getContent());
        $this->assertFalse($resposta->headers->has('X-Robots-Tag'));

        $this->get($this->central('/plataforma/login'))->assertOk();
    }

    public function test_sem_hosts_centrais_a_plataforma_esta_desactivada(): void
    {
        config(['tenancy.hosts_centrais' => []]);

        // Qualquer host: a Plataforma não existe (404 igual ao de uma rota inexistente).
        foreach ([self::CENTRAL, self::ESCOLA, 'localhost'] as $host) {
            $this->get("http://{$host}/plataforma/_fronteira/so-plataforma")->assertNotFound();
        }
        $this->get($this->central('/plataforma/login'))->assertNotFound();

        // A escola fica intacta.
        $this->get($this->escola('/login'))->assertOk();
        $this->get($this->escola('/'))->assertRedirect('/login');

        // Controlo positivo: com host central configurado, a Plataforma volta.
        config(['tenancy.hosts_centrais' => [self::CENTRAL]]);
        $this->get($this->central('/plataforma/_fronteira/so-plataforma'))->assertOk();
    }

    public function test_o_404_da_plataforma_fora_do_host_central_e_igual_ao_de_uma_rota_inexistente(): void
    {
        $inexistente = $this->get($this->escola('/plataforma/_fronteira/nao-existe'));
        $daPlataforma = $this->get($this->escola('/plataforma/_fronteira/so-plataforma'));

        $daPlataforma->assertNotFound();
        $this->assertSame($inexistente->getContent(), $daPlataforma->getContent());
        $this->assertSame($inexistente->headers->has('X-Robots-Tag'), $daPlataforma->headers->has('X-Robots-Tag'));
    }

    // --- Contexto ----------------------------------------------------------------------------

    public function test_host_central_nunca_abre_contexto(): void
    {
        // O espião corre dentro do grupo (e do grupo web, para o controlo positivo).
        app('router')->pushMiddlewareToGroup('plataforma', EspiaDeContexto::class);
        app('router')->pushMiddlewareToGroup('web', EspiaDeContexto::class);

        foreach (['/plataforma/login', '/plataforma/_fronteira/so-plataforma'] as $caminho) {
            $this->semContexto();

            $this->get($this->central($caminho))->assertOk();

            $this->assertFalse(app(TenantContext::class)->temTenant(), "Contexto aberto depois de {$caminho}");
        }
        $this->assertSame([false, false], EspiaDeContexto::$observacoes, 'O espião viu um tenant durante o pedido.');

        // Controlo positivo: no host da escola o espião vê o tenant.
        EspiaDeContexto::$observacoes = [];
        $this->get($this->escola('/_fronteira/leitura-escola'))->assertOk();
        $this->assertSame([true], EspiaDeContexto::$observacoes);
    }

    public function test_leitura_tenant_scoped_no_painel_falha_alto(): void
    {
        $this->semContexto();
        $this->withoutExceptionHandling();

        try {
            $this->get($this->central('/plataforma/_fronteira/leitura'));
            $this->fail('Devia lançar TenantNaoResolvido.');
        } catch (TenantNaoResolvido) {
            $this->assertFalse(app(TenantContext::class)->temTenant());
        }

        // Controlo positivo: a mesma leitura funciona dentro do tenant.
        $this->get($this->escola('/_fronteira/leitura-escola'))->assertOk()->assertSee('cursos:0');
    }

    // --- Sessão e cookie ---------------------------------------------------------------------

    public function test_cookie_da_plataforma_e_proprio(): void
    {
        // Pior caso: o SESSION_DOMAIN do ambiente é o domínio-pai de ambos os hosts.
        config(['session.domain' => '.mositec.test']);

        $daEscola = $this->get($this->escola('/_fronteira/leitura-escola'));
        $daPlataforma = $this->get($this->central('/plataforma/login'));

        $cookieEscola = $this->cookieDeSessao($daEscola, (string) config('session.cookie'));
        $nomePlataforma = Str::slug((string) config('app.name'), '_') . '_plataforma_session';
        $cookiePlataforma = $this->cookieDeSessao($daPlataforma, $nomePlataforma);

        // Controlo positivo: a escola continua com o cookie e o domínio-pai configurados.
        $this->assertSame('.mositec.test', $cookieEscola->getDomain());
        $this->assertNotSame($cookieEscola->getName(), $cookiePlataforma->getName());
        $this->assertNull($cookiePlataforma->getDomain(), 'O cookie da Plataforma não pode levar o domínio-pai.');
        $this->assertTrue($cookiePlataforma->isHttpOnly());

        // A configuração aplicada não vaza para o pedido seguinte da escola (mesmo processo).
        $depois = $this->get($this->escola('/_fronteira/leitura-escola'));
        $this->assertSame('.mositec.test', $this->cookieDeSessao($depois, (string) config('session.cookie'))->getDomain());
    }

    public function test_a_plataforma_aplica_a_sua_duracao_de_sessao(): void
    {
        config(['plataforma.sessao_minutos' => 15, 'session.lifetime' => 120]);

        $daPlataforma = $this->get($this->central('/plataforma/login'));
        $daEscola = $this->get($this->escola('/_fronteira/leitura-escola'));

        $nome = Str::slug((string) config('app.name'), '_') . '_plataforma_session';
        $this->assertEqualsWithDelta(time() + 15 * 60, $this->cookieDeSessao($daPlataforma, $nome)->getExpiresTime(), 5);
        $this->assertEqualsWithDelta(time() + 120 * 60, $this->cookieDeSessao($daEscola, (string) config('session.cookie'))->getExpiresTime(), 5);
        $this->assertLessThan(config('session.lifetime'), config('plataforma.sessao_minutos'));
    }

    public function test_o_valor_por_omissao_da_sessao_da_plataforma_e_mais_curto_que_o_das_escolas(): void
    {
        $this->assertSame(60, config('plataforma.sessao_minutos'));
        $this->assertLessThan((int) config('session.lifetime'), config('plataforma.sessao_minutos'));
    }

    public function test_sessao_da_plataforma_nao_vale_na_escola_nem_vice_versa(): void
    {
        // A autenticação cruzada completa (login dos dois lados) fica para a Task 3. Aqui prova-se
        // a separação de base: o cookie de um mundo não é lido pelo outro e as sessões são distintas.
        $nomeEscola = (string) config('session.cookie');
        $nomePlataforma = Str::slug((string) config('app.name'), '_') . '_plataforma_session';

        $escola = $this->get($this->escola('/_fronteira/leitura-escola'));
        $idEscola = $this->idDaSessao($escola, $nomeEscola);
        $valorEscola = $this->cookieDeSessao($escola, $nomeEscola)->getValue();

        // Controlo positivo: a escola reconhece o seu cookie (mantém o id).
        $outraVez = $this->withUnencryptedCookie($nomeEscola, $valorEscola)->get($this->escola('/_fronteira/leitura-escola'));
        $this->assertSame($idEscola, $this->idDaSessao($outraVez, $nomeEscola));

        // O cookie da escola, enviado à Plataforma, não é lido: nasce uma sessão nova com outro cookie.
        $plataforma = $this->withUnencryptedCookie($nomeEscola, $valorEscola)->get($this->central('/plataforma/login'));
        $idPlataforma = $this->idDaSessao($plataforma, $nomePlataforma);
        $this->assertNotSame($idEscola, $idPlataforma);
        $this->assertNull($plataforma->getCookie($nomeEscola), 'A Plataforma não pode emitir o cookie da escola.');

        // E vice-versa: o cookie da Plataforma, enviado à escola, não é lido.
        $valorPlataforma = $this->cookieDeSessao($plataforma, $nomePlataforma)->getValue();
        $cruzado = $this->withUnencryptedCookie($nomePlataforma, $valorPlataforma)->get($this->escola('/_fronteira/leitura-escola'));
        $this->assertNotSame($idPlataforma, $this->idDaSessao($cruzado, $nomeEscola));
        $this->assertNull($cruzado->getCookie($nomePlataforma), 'A escola não pode emitir o cookie da Plataforma.');
    }

    public function test_sessions_user_id_fica_nulo_para_a_plataforma(): void
    {
        config(['session.driver' => 'database']);
        $nomePlataforma = Str::slug((string) config('app.name'), '_') . '_plataforma_session';

        $resposta = $this->get($this->central('/plataforma/login'));
        $id = $this->idDaSessao($resposta, $nomePlataforma);

        $linha = DB::table('sessions')->where('id', $id)->first();
        $this->assertNotNull($linha, 'A sessão da Plataforma devia estar na tabela sessions.');
        $this->assertNull($linha->user_id);

        // Revogar os acessos de uma escola (sessões por utilizador, tokens) não toca nessa linha.
        $this->noTenant($this->escola, fn () => app(RevogarAcessosDoTenantAction::class)->revogar());
        $this->assertNotNull(DB::table('sessions')->where('id', $id)->first());
    }

    // --- Regressão do grupo web --------------------------------------------------------------

    public function test_o_grupo_web_ja_nao_responde_sem_tenant(): void
    {
        $this->semContexto();

        $pedidos = [
            ['GET', '/cursos'],
            ['POST', '/cursos'],
            ['PUT', '/cursos/1'],
            ['PATCH', '/cursos/1/estado'],
            ['DELETE', '/turmas/1'],
            ['GET', '/api/v1/turmas'],
            ['POST', '/api/v1/turmas'],
            ['PUT', '/api/v1/turmas/1'],
            ['DELETE', '/api/v1/turmas/1'],
        ];

        foreach ($pedidos as [$metodo, $caminho]) {
            // 404 e não 419 (CSRF) nem 401/302: a fronteira vem antes de tudo o resto.
            $this->call($metodo, $this->central($caminho))->assertNotFound();
        }

        // `/up` fica fora dos grupos: responde em qualquer host.
        $this->get($this->central('/up'))->assertOk();
        $this->get($this->escola('/up'))->assertOk();

        // Controlo positivo: no host da escola as mesmas rotas existem (não são 404).
        $this->assertNotSame(404, $this->get($this->escola('/cursos'))->getStatusCode());
        $this->assertNotSame(404, $this->post($this->escola('/cursos'))->getStatusCode());
        $this->assertNotSame(404, $this->getJson($this->escola('/api/v1/turmas'))->getStatusCode());
    }

    // --- Inertia e duração da sessão ----------------------------------------------------------

    public function test_as_props_da_escola_de_um_pedido_anterior_nao_aparecem_no_painel(): void
    {
        Route::middleware('web')->get('/_fronteira/inertia-escola', fn () => Inertia::render('Escola/Qualquer'));
        Route::middleware('plataforma')->get('/plataforma/_fronteira/inertia', fn () => Inertia::render('Qualquer'));

        // Sem a versão certa o Inertia responde 409 (recarregar assets).
        $versao = (string) app(HandleInertiaPlataforma::class)->version(Request::create('/'));
        $cabecalhos = ['X-Inertia' => 'true', 'X-Inertia-Version' => $versao];

        $escola = $this->withHeaders($cabecalhos)->get($this->escola('/_fronteira/inertia-escola'));
        $escola->assertOk();
        $this->assertArrayHasKey('permissoes', $escola->json('props'), 'Controlo positivo: a escola partilha `permissoes`.');

        $painel = $this->withHeaders($cabecalhos)->get($this->central('/plataforma/_fronteira/inertia'));
        $painel->assertOk();
        $props = $painel->json('props');
        $this->assertArrayNotHasKey('permissoes', $props);
        $this->assertArrayHasKey('superAdmin', $props['auth']);
        $this->assertArrayNotHasKey('user', $props['auth']);
        $this->assertSame(['success', 'senha_temporaria'], array_keys($props['flash']));
    }

    public static function duracoesInvalidas(): array
    {
        return ['zero' => [0], 'negativo' => [-5], 'texto' => ['abc'], 'nulo' => [null], 'vazio' => ['']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('duracoesInvalidas')]
    public function test_duracao_de_sessao_invalida_cai_no_valor_seguro(mixed $invalido): void
    {
        config(['plataforma.sessao_minutos' => $invalido]);

        $resposta = $this->get($this->central('/plataforma/login'));

        $nome = Str::slug((string) config('app.name'), '_') . '_plataforma_session';
        $this->assertEqualsWithDelta(time() + 60 * 60, $this->cookieDeSessao($resposta, $nome)->getExpiresTime(), 5);
    }

    // --- Auxiliares --------------------------------------------------------------------------

    private function cookieDeSessao(TestResponse $resposta, string $nome): Cookie
    {
        $cookie = $resposta->getCookie($nome, false);
        $this->assertNotNull($cookie, "A resposta devia emitir o cookie '{$nome}'.");

        return $cookie;
    }

    /** Id da sessão guardado no cookie (cifrado, com prefixo HMAC). */
    private function idDaSessao(TestResponse $resposta, string $nome): string
    {
        $valor = Crypt::decrypt($this->cookieDeSessao($resposta, $nome)->getValue(), false);

        return CookieValuePrefix::remove($valor);
    }
}
