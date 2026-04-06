<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Auth;

class GestaoAutenticacao
{
    public function login($email, $password, $key = null)
    {
        // Primeiro checa rate limiter e tentativa de login
        $limiterResponse = $this->checkLoginAttempts($email, $password, $key);
        if ($limiterResponse !== true) {
             Log::warning('Falha no login', ['email' => $email,'ip' => request()->ip(),'motivo' => $limiterResponse['message'] ]);
            return $limiterResponse;
        }

        $user = Auth::user();
        Log::info('Login realizado com sucesso', ['user_id' => $user->id,'email' => $user->email,'ip' => request()->ip()]);
        $token = $user->createToken('api-token', ['*'], now()->addHours(2))->plainTextToken;

        // Reseta contagem de tentativas em caso de sucesso
        if ($key)
            RateLimiter::clear($key);

        return [
            'success' => true,
            'user' => FormatarRespostaUsuario::formatado($user),
            'token' => $token,
            'code' => 200
        ];
    }

    private function checkLoginAttempts($email, $password, $key = null)
    {
        if ($key && RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
             Log::warning('Usuário bloqueado por muitas tentativas', [
                'email' => $email,
                'ip' => request()->ip(),
                'tempo_restante' => $seconds
            ]);
            return [
                'success' => false,
                'message' => 'Muitas tentativas de login. Tente novamente em ' . $seconds . ' segundos.',
                'code' => 429
            ];
        }

        if (!Auth::attempt(['email' => $email, 'password' => $password])) {
            if ($key)
                RateLimiter::hit($key, 60);
            Log::warning('Credenciais inválidas', [
                'email' => $email,
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
