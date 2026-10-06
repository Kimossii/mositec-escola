<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Estabelecimento\Models\Estabelecimento;

/** Listener em fila SEM ComTenant (só para testes): tem de falhar ao tocar em dados com scope. */
class ListenerSemTrait implements ShouldQueue
{
    public function handle(object $evento): void
    {
        Estabelecimento::current();
    }
}
