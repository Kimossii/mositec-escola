<?php

namespace Tests\Feature\Tenancy;

use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Modules\Autenticacao\Http\Controllers\AutenticacaoController;
use Modules\Autenticacao\Service\LimitadorLogin;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

class FortifyLigacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_existe_recuperacao_automatica_de_palavra_passe(): void
    {
        $this->get($this->urlDoTenant($this->tenant, '/forgot-password'))->assertNotFound();
        $this->post($this->urlDoTenant($this->tenant, '/forgot-password'), ['email' => 'x@example.com'])->assertNotFound();
        $this->get($this->urlDoTenant($this->tenant, '/reset-password/abc?email=x@example.com'))->assertNotFound();
        $this->post($this->urlDoTenant($this->tenant, '/reset-password'), [
            'token' => 'abc', 'email' => 'x@example.com', 'password' => 'segredo1234', 'password_confirmation' => 'segredo1234',
        ])->assertNotFound();

        foreach (app('router')->getRoutes()->getRoutes() as $rota) {
            $this->assertNotContains($rota->getName(), ['password.request', 'password.email', 'password.reset', 'password.update']);
        }

        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
        $this->assertFalse(app()->bound(ResetsUserPasswords::class));
    }

    public function test_nenhum_codigo_usa_o_broker_de_palavras_passe(): void
    {
        $proibidos = ['Password::broker', 'Password::sendResetLink', 'Password::reset(', 'auth.password', 'PasswordBroker', 'sendPasswordResetNotification'];
        $pastas = array_merge([base_path('app')], glob(base_path('Modules/*/app'), GLOB_ONLYDIR));
        $ficheiro = (new ReflectionClass($this))->getFileName();
        $encontrados = [];

        foreach ($pastas as $pasta) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php' || $f->getRealPath() === $ficheiro) {
                    continue;
                }
                $conteudo = file_get_contents($f->getRealPath());
                foreach ($proibidos as $p) {
                    if (str_contains($conteudo, $p)) {
                        $encontrados[] = $f->getRealPath().' => '.$p;
                    }
                }
            }
        }

        $this->assertSame([], $encontrados);
    }

    public function test_o_registo_publico_continua_desactivado(): void
    {
        $this->get($this->urlDoTenant($this->tenant, '/register'))->assertNotFound();
        $this->post($this->urlDoTenant($this->tenant, '/register'), [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'segredo1234', 'password_confirmation' => 'segredo1234',
        ])->assertNotFound();
    }

    public function test_login_e_logout_efectivos_sao_os_do_modulo_autenticacao(): void
    {
        $this->assertStringStartsWith(AutenticacaoController::class, app('router')->getRoutes()->getByName('login.store')->getActionName());
        $this->assertStringStartsWith(AutenticacaoController::class, app('router')->getRoutes()->getByName('logout')->getActionName());
        $this->assertStringStartsWith(AutenticacaoController::class, app('router')->getRoutes()->match(Request::create('/login', 'POST'))->getActionName());
        $this->assertStringStartsWith(AutenticacaoController::class, app('router')->getRoutes()->match(Request::create('/logout', 'POST'))->getActionName());
    }

    public function test_so_existe_o_limitador_de_login_do_modulo(): void
    {
        $this->assertNull(RateLimiter::limiter('login'));
        $this->assertNotNull(RateLimiter::limiter(LimitadorLogin::NOME));
        $this->assertNull(config('fortify.limiters.login'));
    }
}
