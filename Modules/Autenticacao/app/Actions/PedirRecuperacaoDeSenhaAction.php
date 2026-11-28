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
            // Depois de a resposta sair: com a fila em `sync` o envio demoraria no pedido e só as contas elegíveis ficariam
            // lentas (dava para descobrir quem tem conta cronometrando). Com fila real o notify() já só enfileira.
            defer(function () use ($utilizador) {
                try {
                    $utilizador->notify(new RecuperarSenhaNotificacao);
                } catch (Throwable $erro) {
                    // A tentativa falhada fica em `envios_email`; quem pediu já recebeu a resposta igual para todos.
                    Log::warning('Falha ao enviar a ligação de recuperação de senha.', ['causa' => class_basename($erro)]);
                }
            });
        }
    }
}
