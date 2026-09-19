<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limita tentativas de login falhadas através do limiter nomeado "autenticacao.login"
 * (definido em definir(), registado em AutenticacaoServiceProvider), com
 * três baldes:
 *
 * - conta + IP: trava adivinhação de senha a partir de um mesmo cliente;
 * - conta: trava ataques distribuídos por vários IPs à mesma conta;
 * - IP: trava a pulverização de muitas contas a partir de um IP.
 *
 * Só as falhas contam — um login válido não gasta quota, por isso uma
 * escola atrás de NAT não fica bloqueada por utilizadores que acertam.
 */
class LimitadorLogin
{
    public const NOME = 'autenticacao.login';

    public static function definir(): void
    {
        RateLimiter::for(self::NOME, function (Request $request) {
            // O formulário web envia "login" (email ou matrícula), a API "email".
            $identificador = mb_strtolower(trim((string) $request->input('login', $request->input('email', ''))));
            $conta = hash('sha256', $identificador);
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by("conta-ip:{$conta}|{$ip}"),
                Limit::perMinutes(15, 20)->by("conta:{$conta}"),
                Limit::perMinute(30)->by("ip:{$ip}"),
            ];
        });
    }

    /**
     * Segundos até o pedido poder repetir-se, ou null se não está bloqueado.
     */
    public function segundosDeBloqueio(Request $request): ?int
    {
        $espera = null;

        foreach ($this->limites($request) as $limite) {
            if (RateLimiter::tooManyAttempts($limite->key, $limite->maxAttempts)) {
                $espera = max($espera ?? 0, RateLimiter::availableIn($limite->key));
            }
        }

        return $espera;
    }

    public function registarFalha(Request $request): void
    {
        foreach ($this->limites($request) as $limite) {
            RateLimiter::hit($limite->key, $limite->decaySeconds);
        }
    }

    /**
     * Repõe só os baldes da conta (os dois primeiros). O balde por IP fica
     * intocado: senão quem tem uma conta válida repunha a sua quota de
     * pulverização a cada login com sucesso.
     */
    public function limparConta(Request $request): void
    {
        foreach (array_slice($this->limites($request), 0, 2) as $limite) {
            RateLimiter::clear($limite->key);
        }
    }

    public static function mensagem(int $segundos): string
    {
        return 'Muitas tentativas de login. Tente novamente em '.$segundos.' segundos.';
    }

    /**
     * @return Limit[]
     */
    private function limites(Request $request): array
    {
        return RateLimiter::limiter(self::NOME)($request);
    }
}
