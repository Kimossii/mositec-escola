<?php

namespace Modules\Core\Comunicacao\Email\Notificacoes;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\NotificacaoDeTenant;

/** Aviso à escola: a quota mensal de emails opcionais já passou o limiar de aviso. ESSENCIAL (nunca depende da quota). */
class QuotaDeEmailNoAvisoNotificacao extends NotificacaoDeTenant
{
    public function __construct(private int $usado, private int $limite) {}

    public function categoria(): CategoriaEmail
    {
        return CategoriaEmail::ESSENCIAL;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mensagemBase()
            ->subject('A quota mensal de emails está a esgotar-se')
            ->line("A escola já enviou {$this->usado} dos {$this->limite} emails opcionais incluídos este mês.")
            ->line('Quando a quota acabar, os emails opcionais deixam de sair até ao mês seguinte. Os essenciais (recuperação de senha, confirmação de email) continuam sempre a sair.');
    }
}
