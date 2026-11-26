<?php

namespace Tests\Fixtures\Comunicacao;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\NotificacaoDeTenant;
use Modules\Core\Comunicacao\Email\RemetenteTenant;

class NotificacaoDeTeste extends NotificacaoDeTenant
{
    public function __construct(private CategoriaEmail $categoria = CategoriaEmail::ESSENCIAL) {}

    public function categoria(): CategoriaEmail
    {
        return $this->categoria;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mensagemBase()
            ->subject('Teste')
            ->action('Entrar', app(RemetenteTenant::class)->url('/login'));
    }
}
