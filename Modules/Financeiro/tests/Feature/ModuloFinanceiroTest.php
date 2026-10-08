<?php

namespace Modules\Financeiro\Tests\Feature;

use Nwidart\Modules\Facades\Module;
use Tests\TestCase;

class ModuloFinanceiroTest extends TestCase
{
    public function test_modulo_financeiro_esta_activo(): void
    {
        $this->assertTrue(Module::isEnabled('Financeiro'));
    }
}
