<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use SensitiveParameter;
use Modules\Core\Traits\LogService;

class GestaoAutenticacao
{
    use LogService;

    public function __construct(private LimitadorLogin $limitador)
    {
    }

    public function login(Request $request, $identificador, #[SensitiveParameter] $password, $remember = false)
    {
        $limiterResponse = $this->checkLoginAttempts($request, $identificador, $password, $remember);
        if ($limiterResponse !== true) {
            $this->logWarning('Falha no login', ['identificador' => $identificador, 'ip' => $request->ip(), 'motivo' => $limiterResponse['message']]);
            return $limiterResponse;
        }

        $user = Auth::user();
        $this->logInfo('Login realizado com sucesso', ['user_id' => $user->id, 'identificador' => $identificador, 'ip' => $request->ip()]);

        $this->limitador->limparConta($request);

        return [
            'success' => true,
            'user' => $user,
            'code' => 200
        ];
    }

    private function checkLoginAttempts(Request $request, $identificador, #[SensitiveParameter] $password, $remember = false)
    {
        $segundos = $this->limitador->segundosDeBloqueio($request);
        if ($segundos !== null) {
            $this->logWarning('Usuário bloqueado por muitas tentativas', [
                'identificador' => $identificador,
                'ip' => $request->ip(),
                'tempo_restante' => $segundos
            ]);
            return [
                'success' => false,
                'message' => LimitadorLogin::mensagem($segundos),
                'code' => 429
            ];
        }

        $campo = str_contains($identificador, '@') ? 'email' : 'numero_matricula';

        if (!Auth::attempt([$campo => $identificador, 'password' => $password], $remember)) {
            $this->limitador->registarFalha($request);
            $this->logWarning('Credenciais inválidas', [
                'identificador' => $identificador,
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
