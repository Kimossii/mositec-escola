<?php

namespace Modules\Core\Comunicacao\Email\Notificacoes;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\NotificacaoDeTenant;

/** Aviso à escola: a quota mensal de emails opcionais esgotou-se. ESSENCIAL (nunca depende da quota). */
class QuotaDeEmailEsgotadaNotificacao extends NotificacaoDeTenant
{
    public function __construct(private int $limite, private bool $bloqueia) {}

    public function categoria(): CategoriaEmail
    {
        return CategoriaEmail::ESSENCIAL;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mensagem = $this->mensagemBase()
            ->subject('A quota mensal de emails esgotou-se')
            ->line("A escola atingiu os {$this->limite} emails opcionais incluídos este mês.");

        return $this->bloqueia
            ? $mensagem->line('Os emails opcionais ficam suspensos até ao mês seguinte. Os essenciais (recuperação de senha, confirmação de email) continuam a sair.')
            : $mensagem->line('Os emails continuam a sair, mas o consumo passou o incluído no plano.');
    }
}
