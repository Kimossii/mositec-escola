<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Modules\Core\Tenancy\CacheTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Models\User;

class GestaoAutenticacaoAPI
{
    use LogService;

    /** Validade do desafio 2FA: tempo para abrir a aplicação de códigos e escrever o código. */
    private const MINUTOS_DO_DESAFIO = 5;

    private const TENTATIVAS_DO_DESAFIO = 5;

    public function __construct(private LimitadorLogin $limitador, private CacheTenant $cache, private ValidadorDeCredenciais $validador)
    {
    }

    public function login(Request $request, $email, $password)
    {
        // Primeiro checa rate limiter e tentativa de login
        $limiterResponse = $this->checkLoginAttempts($request, $email, $password);
        if (is_array($limiterResponse)) {
             $this->logWarning('Falha no login', ['email' => $email,'ip' => $request->ip(),'motivo' => $limiterResponse['message'] ]);
            return $limiterResponse;
        }

        $user = $limiterResponse;

        // Reseta contagem de tentativas em caso de sucesso
        $this->limitador->limparConta($request);

        // Com 2FA a senha certa ainda não dá token: devolve-se um desafio para trocar por código.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            // O limite é da conta, não do desafio: sem isto bastava pedir um desafio novo a cada 5 tentativas.
            if (RateLimiter::tooManyAttempts($this->limiteDoDesafio($user), self::TENTATIVAS_DO_DESAFIO)) {
                $this->logWarning('Novo desafio 2FA recusado: conta bloqueada por tentativas', ['user_id' => $user->id, 'ip' => $request->ip()]);

                return ['success' => false, 'message' => 'Demasiadas tentativas de código. Tente de novo dentro de instantes.', 'code' => 429];
            }

            $this->logInfo('Senha validada; falta o segundo factor', ['user_id' => $user->id, 'ip' => $request->ip()]);

            return ['success' => true, 'requer_2fa' => true, 'desafio' => $this->criarDesafio($user), 'code' => 202];
        }

        $this->logInfo('Login realizado com sucesso', ['user_id' => $user->id,'email' => $user->email,'ip' => $request->ip()]);

        return $this->emitirToken($user);
    }

    /** Segundo passo: troca o `desafio` + código TOTP (ou de recuperação) por token. */
    public function concluirDesafio(Request $request, string $desafio, ?string $codigo, ?string $codigoDeRecuperacao): array
    {
        $chave = $this->chaveDoDesafio($desafio);
        $userId = $this->cache->get($chave);
        $user = $userId === null ? null : User::find($userId);

        if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()) {
            return ['success' => false, 'message' => 'Desafio inválido ou expirado.', 'code' => 422];
        }

        $limite = $this->limiteDoDesafio($user);

        // Conta-se ANTES de verificar o código: `hit` é atómico, por isso pedidos em paralelo não passam todos o limite.
        if (RateLimiter::hit($limite, 60) > self::TENTATIVAS_DO_DESAFIO) {
            $this->logWarning('Desafio 2FA bloqueado por tentativas', ['user_id' => $user->id, 'ip' => $request->ip()]);

            return ['success' => false, 'message' => 'Demasiadas tentativas. Tente de novo dentro de instantes.', 'code' => 429];
        }

        if ((int) $user->estado !== 1) {
            $this->logWarning('Desafio 2FA recusado: conta desactivada', ['user_id' => $user->id]);

            return ['success' => false, 'message' => 'Esta conta está desactivada.', 'code' => 403];
        }

        if (! $this->codigoValido($user, $codigo, $codigoDeRecuperacao)) {
            $this->logWarning('Código 2FA inválido', ['user_id' => $user->id, 'ip' => $request->ip()]);

            return ['success' => false, 'message' => 'Código inválido.', 'code' => 422];
        }

        // Uso único e atómico: só quem apagar a chave primeiro recebe o token.
        $trinco = $this->cache->lock($chave.':uso', 5);
        if (! $trinco->get()) {
            return ['success' => false, 'message' => 'Desafio inválido ou expirado.', 'code' => 422];
        }
        try {
            if ($this->cache->get($chave) === null) {
                return ['success' => false, 'message' => 'Desafio inválido ou expirado.', 'code' => 422];
            }
            $this->cache->forget($chave);
        } finally {
            $trinco->release();
        }

        RateLimiter::clear($limite);
        $this->logInfo('Login realizado com sucesso (2FA)', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return $this->emitirToken($user);
    }

    /** Limitador por conta (e escola): partilhado por todos os desafios pendentes dessa conta. */
    private function limiteDoDesafio(User $user): string
    {
        return 't'.app(TenantContext::class)->id().'|2fa-api|user:'.$user->getKey();
    }

    private function codigoValido(User $user, ?string $codigo, ?string $codigoDeRecuperacao): bool
    {
        if ($codigoDeRecuperacao !== null && $codigoDeRecuperacao !== '') {
            $encontrado = collect($user->recoveryCodes())->first(fn (string $c) => hash_equals($c, $codigoDeRecuperacao));
            if ($encontrado === null) {
                return false;
            }
            $user->replaceRecoveryCode($encontrado);

            return true;
        }

        return $codigo !== null && $codigo !== ''
            && app(TwoFactorAuthenticationProvider::class)->verify(decrypt($user->two_factor_secret), $codigo);
    }

    private function criarDesafio(User $user): string
    {
        $desafio = Str::random(40);
        $this->cache->put($this->chaveDoDesafio($desafio), $user->getKey(), now()->addMinutes(self::MINUTOS_DO_DESAFIO));

        return $desafio;
    }

    /** Só o hash do desafio vai para a cache; a chave leva o prefixo do tenant (CacheTenant). */
    private function chaveDoDesafio(string $desafio): string
    {
        return '2fa-desafio:'.hash('sha256', $desafio);
    }

    private function emitirToken(User $user): array
    {
        $token = $user->createToken('api-token', ['*'], now()->addHours(2))->plainTextToken;

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
             $this->logWarning('Usuário bloqueado por muitas tentativas', [
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

        // Valida sem iniciar sessão: quem tem 2FA só recebe token depois do desafio.
        $credenciais = ['email' => $email, 'password' => $password];
        $utilizador = $this->validador->validar($credenciais);

        if ($utilizador === null) {
            $this->limitador->registarFalha($request);
            $this->logWarning('Credenciais inválidas', [
                'email' => $email,
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
