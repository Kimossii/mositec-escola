<?php

namespace Modules\Autenticacao\Actions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Autenticacao\Exceptions\ConfirmacaoDeEmailRecusada;
use Modules\Autenticacao\Notifications\ConfirmarEmailNotificacao;
use Modules\Core\Comunicacao\Email\EmailDisponivel;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Throwable;

/**
 * «Enviar confirmação» do email de acesso, por pedido (do próprio utilizador, ou de quem o administrar). Só envia se
 * houver um canal de email configurado, se a conta tiver email de login por confirmar, e dentro do limite por hora.
 */
class EnviarConfirmacaoDeEmailAction
{
    use LogService;

    public function __construct(private EmailDisponivel $emailDisponivel) {}

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $alvo): void
    {
        $this->comLog('Enviar confirmação de email', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($alvo));
    }

    /** @throws ConfirmacaoDeEmailRecusada */
    private function executarSemRegisto(User $alvo): void
    {
        if (! $this->emailDisponivel->disponivel()) {
            throw ConfirmacaoDeEmailRecusada::emailIndisponivel();
        }

        if (($alvo->tipo_login ?? TipoLogin::EMAIL) !== TipoLogin::EMAIL || trim((string) $alvo->email) === '') {
            throw ConfirmacaoDeEmailRecusada::semEmail();
        }

        if ($alvo->email_verified_at !== null) {
            throw ConfirmacaoDeEmailRecusada::jaConfirmado();
        }

        $chave = 'confirmacao-email:'.$alvo->id;
        $limite = (int) config('comunicacao.confirmacao_email.pedidos_por_hora');

        if (RateLimiter::tooManyAttempts($chave, $limite)) {
            throw ConfirmacaoDeEmailRecusada::demasiadosPedidos();
        }

        RateLimiter::hit($chave, 3600);

        try {
            $alvo->notify(new ConfirmarEmailNotificacao);
        } catch (Throwable $erro) {
            // Com a fila em `sync` uma falha do provedor subiria como erro 500; fica registada em `envios_email`.
            Log::warning('Falha ao enviar a ligação de confirmação de email.', ['causa' => class_basename($erro)]);

            throw ConfirmacaoDeEmailRecusada::falhaNoEnvio();
        }
    }
}
