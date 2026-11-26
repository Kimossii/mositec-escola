<?php

namespace Modules\Autenticacao\Actions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Autenticacao\Notifications\RecuperarSenhaNotificacao;
use Modules\Autenticacao\Support\ElegibilidadeParaRecuperarSenha;
use Modules\Core\Comunicacao\Email\EmailDisponivel;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Models\User;
use Throwable;

/**
 * «Esqueci a senha». Não devolve NADA a quem pede: existir ou não conta, ser ou não elegível, estar ou não no limite, tudo
 * dá a mesma resposta (o controlador responde sempre igual), para ninguém descobrir quem tem conta. O limite por hora conta
 * TODOS os pedidos para o mesmo email (também os sem conta), pelo mesmo motivo.
 */
class PedirRecuperacaoDeSenhaAction
{
    use LogService;

    public function __construct(
        private EmailDisponivel $emailDisponivel,
        private ElegibilidadeParaRecuperarSenha $elegibilidade,
        private TenantContext $contexto,
    ) {}

    /** Regista só que houve um pedido, nunca o email pedido. */
    public function executar(string $email): void
    {
        $this->comLog('Pedir recuperação de senha', [], fn () => $this->executarSemRegisto($email));
    }

    private function executarSemRegisto(string $email): void
    {
        $email = mb_strtolower(trim($email));

        if (! $this->emailDisponivel->disponivel() || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $chave = 'recuperar-senha:'.$this->contexto->id().':'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($chave, (int) config('comunicacao.recuperacao_senha.pedidos_por_hora'))) {
            return;
        }

        RateLimiter::hit($chave, 3600);

        $utilizador = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($utilizador !== null && $this->elegibilidade->permite($utilizador)) {
            try {
                $utilizador->notify(new RecuperarSenhaNotificacao);
            } catch (Throwable $erro) {
                // Com a fila em `sync` uma falha do provedor subiria até aqui e a resposta deixaria de ser igual para todos
                // (só as contas elegíveis dariam erro). A tentativa falhada fica em `envios_email`; quem pede vê sempre o mesmo.
                Log::warning('Falha ao enviar a ligação de recuperação de senha.', ['causa' => class_basename($erro)]);
            }
        }
    }
}
