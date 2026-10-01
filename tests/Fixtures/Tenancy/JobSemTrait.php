<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Estabelecimento\Models\Estabelecimento;

/** Job de exemplo SEM ComTenant (só para testes): toca em dados com scope e tem de falhar. */
class JobSemTrait implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Estabelecimento::current();
    }
}
