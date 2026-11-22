<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use SensitiveParameter;
use Modules\Core\Traits\LogService;

class GestaoAutenticacao
{
    use LogService;

    public function __construct(private LimitadorLogin $limitador, private ValidadorDeCredenciais $validador)
    {
    }

    public function login(Request $request, $identificador, #[SensitiveParameter] $password, $remember = false)
    {
        $limiterResponse = $this->checkLoginAttempts($request, $identificador, $password, $remember);
        if (is_array($limiterResponse)) {
            $this->logWarning('Falha no login', ['identificador' => $identificador, 'ip' => $request->ip(), 'motivo' => $limiterResponse['message']]);
            return $limiterResponse;
        }

        $user = $limiterResponse;

        // Com 2FA a senha certa ainda não autentica: a sessão só abre depois do desafio (POST /two-factor-challenge).
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->logInfo('Senha validada; falta o segundo factor', ['user_id' => $user->id, 'ip' => $request->ip()]);
            $this->limitador->limparConta($request);

            return ['success' => true, 'user' => $user, 'requer_2fa' => true, 'code' => 200];
        }

        Auth::login($user, $remember);
        $this->logInfo('Login realizado com sucesso', ['user_id' => $user->id, 'identificador' => $identificador, 'ip' => $request->ip()]);

        $this->limitador->limparConta($request);

        return [
            'success' => true,
            'user' => $user,
            'requer_2fa' => false,
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

        // Valida sem iniciar sessão: quem tem 2FA só entra depois do desafio.
        $credenciais = [$campo => $identificador, 'password' => $password];
        $utilizador = $this->validador->validar($credenciais);

        if ($utilizador === null) {
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

        // Só depois de a senha estar certa (não revela contas desactivadas a quem não a sabe).
        if ((int) $utilizador->estado !== 1) {
            $this->logWarning('Login recusado: conta desactivada', ['user_id' => $utilizador->id, 'ip' => $request->ip()]);

            return [
                'success' => false,
                'message' => 'Esta conta está desactivada. Contacte o administrador da escola.',
                'code' => 403,
            ];
        }

        return $utilizador;
    }
}
