<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Core\Tenancy\Jobs\ComTenant;

/** Job de lote COM ComTenant (só para testes). */
class JobDeLoteDeTeste implements ShouldQueue
{
    use Batchable, ComTenant, Queueable;

    public function handle(): void {}
}
