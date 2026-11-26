<?php

namespace Modules\Autenticacao\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Autenticacao\Support\HashDeRecuperacaoDeSenha;
use Modules\Autenticacao\Support\LigacaoAssinadaDoTenant;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\NotificacaoDeTenant;
use Modules\Usuario\Models\User;

/**
 * Ligação para escolher uma nova senha. ESSENCIAL. A ligação é assinada, expira, serve uma vez (deixa de valer quando a senha ou
 * o email mudam) e leva só o id e uma impressão do estado da conta: nunca a senha, o email nem qualquer segredo.
 */
class RecuperarSenhaNotificacao extends NotificacaoDeTenant
{
    public function categoria(): CategoriaEmail
    {
        return CategoriaEmail::ESSENCIAL;
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $minutos = (int) config('comunicacao.recuperacao_senha.validade_minutos');
        $ligacao = LigacaoAssinadaDoTenant::gerar('senha.recuperar', $minutos, [
            'id' => $notifiable->id,
            'hash' => HashDeRecuperacaoDeSenha::de($notifiable),
        ]);

        return $this->mensagemBase()
            ->subject('Recuperação de senha')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Recebemos um pedido para escolher uma nova senha para a sua conta.')
            ->action('Escolher nova senha', $ligacao)
            ->line("Esta ligação é válida durante {$minutos} minutos e só pode ser usada uma vez.")
            ->line('Se não fez este pedido, ignore esta mensagem: a sua senha actual continua a funcionar.');
    }
}
