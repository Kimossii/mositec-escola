<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Auth;

class GestaoAutenticacao
{
    public function login($identificador, $password, $remember = false, $key = null)
    {
        $limiterResponse = $this->checkLoginAttempts($identificador, $password, $remember, $key);
        if ($limiterResponse !== true) {
            Log::warning('Falha no login', ['identificador' => $identificador, 'ip' => request()->ip(), 'motivo' => $limiterResponse['message']]);
            return $limiterResponse;
        }

        $user = Auth::user();
        Log::info('Login realizado com sucesso', ['user_id' => $user->id, 'identificador' => $identificador, 'ip' => request()->ip()]);

        if ($key)
            RateLimiter::clear($key);

        return [
            'success' => true,
            'user' => $user,
            'code' => 200
        ];
    }

    private function checkLoginAttempts($identificador, $password, $remember = false, $key = null)
    {
        if ($key && RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            Log::warning('Usuário bloqueado por muitas tentativas', [
                'identificador' => $identificador,
                'ip' => request()->ip(),
                'tempo_restante' => $seconds
            ]);
            return [
                'success' => false,
                'message' => 'Muitas tentativas de login. Tente novamente em ' . $seconds . ' segundos.',
                'code' => 429
            ];
        }

        $campo = str_contains($identificador, '@') ? 'email' : 'numero_matricula';

        if (!Auth::attempt([$campo => $identificador, 'password' => $password], $remember)) {
            if ($key)
                RateLimiter::hit($key, 60);
            Log::warning('Credenciais inválidas', [
                'identificador' => $identificador,
                'ip' => request()->ip()
            ]);
            return [
                'success' => false,
                'message' => 'Credenciais inválidas',
                'code' => 401
            ];
        }

        return true;
    }
}
