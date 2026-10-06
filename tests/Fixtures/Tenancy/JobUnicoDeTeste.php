<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Core\Tenancy\Jobs\ComTenant;

/** Job único COM ComTenant (só para testes). */
class JobUnicoDeTeste implements ShouldBeUnique, ShouldQueue
{
    use ComTenant, Queueable;

    public function handle(): void {}
}
