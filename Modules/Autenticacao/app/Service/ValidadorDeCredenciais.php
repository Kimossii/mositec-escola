<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Timebox;
use Modules\Usuario\Models\User;
use SensitiveParameter;

/**
 * Valida email/matrícula + senha SEM abrir sessão (quem tem 2FA só entra depois do segundo factor), mantendo
 * o que `Auth::attempt` fazia por baixo: tempo de resposta igualado (não se descobre que contas existem pelo
 * tempo) e actualização do hash quando o custo do bcrypt mudou.
 */
class ValidadorDeCredenciais
{
    /** Mesmo valor do SessionGuard: 200 ms. */
    private const MICROSEGUNDOS = 200 * 1000;

    public function __construct(private Timebox $timebox) {}

    /** @param  array<string, mixed>  $credenciais  campo de identificação + 'password' */
    public function validar(#[SensitiveParameter] array $credenciais): ?User
    {
        $provider = Auth::guard()->getProvider();

        return $this->timebox->call(function (Timebox $timebox) use ($provider, $credenciais) {
            $utilizador = $provider->retrieveByCredentials($credenciais);

            if ($utilizador === null || ! $provider->validateCredentials($utilizador, $credenciais)) {
                return null;
            }

            // Sucesso: não vale a pena atrasar quem acertou.
            $timebox->returnEarly();
            $provider->rehashPasswordIfRequired($utilizador, $credenciais);

            return $utilizador;
        }, self::MICROSEGUNDOS);
    }
}
