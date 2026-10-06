<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Modules\Estabelecimento\Models\Estabelecimento;

/** Notification em fila COM ComTenant (só para testes), entregue por um canal que regista o que vê. */
class NotificacaoDeTeste extends Notification implements ShouldQueue
{
    use ComTenant, Queueable;

    /** @var list<string> */
    public static array $vistos = [];

    public function via(object $notifiable): array
    {
        return [self::class];
    }

    /** Canal por classe: o Laravel resolve-o por `send($notifiable, $notification)`. */
    public function send(object $notifiable, Notification $notificacao): void
    {
        self::$vistos[] = Estabelecimento::current()->nome;
    }
}
