<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Modules\Estabelecimento\Models\Estabelecimento;

/** Mailable em fila COM ComTenant (só para testes): o assunto é o nome da escola. */
class MailableDeTeste extends Mailable implements ShouldQueue
{
    use ComTenant, Queueable, SerializesModels;

    public function build(): static
    {
        return $this->subject(Estabelecimento::current()->nome)->html('<p>olá</p>');
    }
}
