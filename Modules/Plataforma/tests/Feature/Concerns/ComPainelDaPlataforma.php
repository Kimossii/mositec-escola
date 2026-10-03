<?php

namespace Modules\Plataforma\Tests\Feature\Concerns;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Modules\Core\Tenancy\TenantContext;
use Modules\Plataforma\Http\Middleware\ConfigurarSessaoPlataforma;
use Modules\Plataforma\Http\Middleware\HandleInertiaPlataforma;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Pedidos ao painel como se cada um fosse um processo novo (guards e Store de sessão limpos),
 * com login real. Mesma técnica do AutenticacaoPlataformaTest: em produção cada pedido é um
 * processo, e nos testes o Store seria reutilizado e herdaria atributos de pedidos anteriores.
 * Quem usa define `config(['tenancy.hosts_centrais' => [self::CENTRAL]])` no setUp.
 */
trait ComPainelDaPlataforma
{
    private const CENTRAL = 'painel.mositec.test';

    private const SENHA_DO_ADMIN = 'Senha-Muito-Segura-1!';

    private function urlCentral(string $caminho): string
    {
        return 'http://' . self::CENTRAL . $caminho;
    }

    private function superAdmin(string $email = 'rui@plataforma.test', bool $activo = true): SuperAdmin
    {
        return SuperAdmin::create([
            'name' => 'Rui Operador',
            'email' => $email,
            'password' => Hash::make(self::SENHA_DO_ADMIN),
            'estado' => $activo ? 1 : 0,
        ]);
    }

    /** Pedido como se fosse um processo novo; o contexto de tenant começa (e, no painel, acaba) vazio. */
    private function pedido(string $metodo, string $url, array $dados = [], array $cookies = [], array $cabecalhos = [], string $ip = '10.0.0.1'): TestResponse
    {
        app(TenantContext::class)->limpar();

        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        foreach (app('session')->getDrivers() as $driver) {
            $driver->flush();

            if (method_exists($driver->getHandler(), 'setExists')) {
                $driver->getHandler()->setExists(false);
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

    private function cabecalhosInertia(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaPlataforma::class)->version(Request::create('/')),
        ];
    }

    private function idDaSessao(TestResponse $resposta): string
    {
        $cookie = $resposta->getCookie(ConfigurarSessaoPlataforma::nomeDoCookie(), false);
        $this->assertNotNull($cookie, 'A resposta devia emitir o cookie da sessão da Plataforma.');

        return CookieValuePrefix::remove(Crypt::decrypt($cookie->getValue(), false));
    }

    /** Login real no painel; devolve o id da sessão autenticada. */
    private function entrarNoPainel(SuperAdmin $admin): string
    {
        $resposta = $this->pedido('POST', $this->urlCentral('/plataforma/login'), ['email' => $admin->email, 'password' => self::SENHA_DO_ADMIN]);
        $resposta->assertRedirect($this->urlCentral('/plataforma'));

        return $this->idDaSessao($resposta);
    }

    /** Pedido ao painel com uma sessão (id) já aberta. */
    private function noPainel(string $metodo, string $caminho, string $sessao, array $dados = [], array $cabecalhos = []): TestResponse
    {
        return $this->pedido($metodo, $this->urlCentral($caminho), $dados, [ConfigurarSessaoPlataforma::nomeDoCookie() => $sessao], $cabecalhos);
    }
}
