<?php

namespace Modules\Autenticacao\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Autenticacao\Support\HashDeConfirmacaoDeEmail;
use Modules\Autenticacao\Support\LigacaoAssinadaDoTenant;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\NotificacaoDeTenant;
use Modules\Usuario\Models\User;

/**
 * Pede ao utilizador que confirme o email de acesso. ESSENCIAL: sem confirmação a recuperação de senha por email
 * não fica activa. A ligação é assinada (não se pode alterar), expira, e está ligada ao email actual (se o email
 * mudar, a ligação antiga deixa de servir). Leva o id do utilizador, nunca a senha nem qualquer token de sessão.
 */
class ConfirmarEmailNotificacao extends NotificacaoDeTenant
{
    public function categoria(): CategoriaEmail
    {
        return CategoriaEmail::ESSENCIAL;
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $minutos = (int) config('comunicacao.confirmacao_email.validade_minutos');

        return $this->mensagemBase()
            ->subject('Confirme o seu email')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Recebemos um pedido para confirmar este email como o email de acesso à sua conta.')
            ->action('Confirmar email', $this->ligacao($notifiable, $minutos))
            ->line("Esta ligação é válida durante {$minutos} minutos. Se não fez este pedido, ignore esta mensagem.");
    }

    /** Gerada aqui (já dentro do contexto do tenant) para apontar ao domínio principal da escola, não ao do worker. */
    private function ligacao(User $utilizador, int $minutos): string
    {
        return LigacaoAssinadaDoTenant::gerar('email.confirmar', $minutos, ['id' => $utilizador->id, 'hash' => HashDeConfirmacaoDeEmail::de($utilizador)]);
    }
}
