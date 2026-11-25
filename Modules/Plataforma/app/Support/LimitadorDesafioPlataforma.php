<?php

namespace Modules\Plataforma\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Plataforma\Models\SuperAdmin;

/** Limita as tentativas FALHADAS do código de 2FA: 5 por minuto por conta + IP. Prefixo `p|desafio:`, independente do login. */
class LimitadorDesafioPlataforma
{
    private const MAXIMO = 5;

    private const SEGUNDOS = 60;

    public function segundosDeBloqueio(SuperAdmin $admin, Request $request): ?int
    {
        $chave = $this->chave($admin, $request);

        return RateLimiter::tooManyAttempts($chave, self::MAXIMO) ? RateLimiter::availableIn($chave) : null;
    }

    public function registarFalha(SuperAdmin $admin, Request $request): void
    {
        RateLimiter::hit($this->chave($admin, $request), self::SEGUNDOS);
    }

    public function limpar(SuperAdmin $admin, Request $request): void
    {
        RateLimiter::clear($this->chave($admin, $request));
    }

    private function chave(SuperAdmin $admin, Request $request): string
    {
        return "p|desafio:{$admin->id}|{$request->ip()}";
    }
}
