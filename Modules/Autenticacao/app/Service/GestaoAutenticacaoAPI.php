<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class GestaoAutenticacaoAPI
{
    public function __construct(private LimitadorLogin $limitador)
    {
    }

    public function login(Request $request, $email, $password)
    {
        // Primeiro checa rate limiter e tentativa de login
        $limiterResponse = $this->checkLoginAttempts($request, $email, $password);
        if ($limiterResponse !== true) {
             Log::warning('Falha no login', ['email' => $email,'ip' => $request->ip(),'motivo' => $limiterResponse['message'] ]);
            return $limiterResponse;
        }

        $user = Auth::user();
        Log::info('Login realizado com sucesso', ['user_id' => $user->id,'email' => $user->email,'ip' => $request->ip()]);
        $token = $user->createToken('api-token', ['*'], now()->addHours(2))->plainTextToken;

        // Reseta contagem de tentativas em caso de sucesso
        $this->limitador->limparConta($request);

        return [
            'success' => true,
            'user' => FormatarRespostaUsuario::formatado($user),
            'token' => $token,
            'code' => 200
        ];
    }

    private function checkLoginAttempts(Request $request, $email, $password)
    {
        $segundos = $this->limitador->segundosDeBloqueio($request);
        if ($segundos !== null) {
             Log::warning('Usuário bloqueado por muitas tentativas', [
                'email' => $email,
                'ip' => $request->ip(),
                'tempo_restante' => $segundos
            ]);
            return [
                'success' => false,
                'message' => LimitadorLogin::mensagem($segundos),
                'code' => 429
            ];
        }

        if (!Auth::attempt(['email' => $email, 'password' => $password])) {
            $this->limitador->registarFalha($request);
            Log::warning('Credenciais inválidas', [
                'email' => $email,
                'ip' => $request->ip()
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
