<?php

namespace Modules\Autenticacao\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\NotificacaoDeTenant;
use Modules\Core\Comunicacao\Email\RemetenteTenant;
use Modules\Usuario\Models\User;

/** Aviso de segurança: a senha da conta mudou. ESSENCIAL. Nunca leva a senha. Dá à pessoa a hipótese de reagir se não foi ela. */
class SenhaAlteradaNotificacao extends NotificacaoDeTenant
{
    public function __construct(private string $quando) {}

    public function categoria(): CategoriaEmail
    {
        return CategoriaEmail::ESSENCIAL;
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        return $this->mensagemBase()
            ->subject('A sua senha foi alterada')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line("A senha da sua conta foi alterada em {$this->quando}. Por segurança, terminámos as sessões que estavam abertas.")
            ->action('Entrar', app(RemetenteTenant::class)->url('/login'))
            ->line('Se não foi você, fale de imediato com o administrador da escola.');
    }
}
