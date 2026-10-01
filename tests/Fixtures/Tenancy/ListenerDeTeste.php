<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Modules\Estabelecimento\Models\Estabelecimento;

/** Listener em fila COM ComTenant (só para testes). */
class ListenerDeTeste implements ShouldQueue
{
    use ComTenant, InteractsWithQueue;

    /** @var list<string> */
    public static array $vistos = [];

    public function handle(object $evento): void
    {
        self::$vistos[] = Estabelecimento::current()->nome;
    }
}
