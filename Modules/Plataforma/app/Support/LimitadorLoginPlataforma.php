<?php

namespace Modules\Plataforma\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limita as tentativas de login FALHADAS no painel através do limiter nomeado "plataforma.login":
 *
 * - e-mail + IP: trava a adivinhação de senha a partir de um mesmo cliente;
 * - IP: trava a pulverização de muitas contas a partir de um IP.
 *
 * É independente do LimitadorLogin das escolas: as chaves levam o prefixo `p|` (e não há tenant),
 * por isso falhas no painel não bloqueiam o login de uma escola e vice-versa. Só as falhas contam.
 */
class LimitadorLoginPlataforma
{
    public const NOME = 'plataforma.login';

    public static function definir(): void
    {
        RateLimiter::for(self::NOME, function (Request $request) {
            $conta = hash('sha256', mb_strtolower(trim((string) $request->input('email', ''))));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by("p|conta-ip:{$conta}|{$ip}"),
                Limit::perMinute(30)->by("p|ip:{$ip}"),
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
     * Repõe só o balde e-mail + IP. O balde por IP fica intocado: senão quem tem uma conta válida
     * repunha a sua quota de pulverização a cada login com sucesso.
     */
    public function limparConta(Request $request): void
    {
        RateLimiter::clear($this->limites($request)[0]->key);
    }

    /**
     * @return Limit[]
     */
    private function limites(Request $request): array
    {
        return RateLimiter::limiter(self::NOME)($request);
    }
}
