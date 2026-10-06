<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Modules\Core\Tenancy\Jobs\UnicoPorTenant;

/** Job único COM ComTenant e UnicoPorTenant (só para testes). */
class JobUnicoPorTenantDeTeste implements ShouldBeUnique, ShouldQueue
{
    use ComTenant, Queueable, UnicoPorTenant;

    public function __construct(public string $chave = 'x') {}

    protected function identificadorUnico(): string
    {
        return $this->chave;
    }

    public function handle(): void {}
}
