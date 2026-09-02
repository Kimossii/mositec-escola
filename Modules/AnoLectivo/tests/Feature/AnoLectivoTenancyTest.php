<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnoLectivoTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_isolamento_entre_tenants(): void
    {
        // @todo: o MosiTec é single-tenant hoje (sem tenant_id/GlobalScope
        // em nenhuma tabela do projecto — ver docs/superpowers/specs/
        // 2026-08-30-modulo-anolectivo-design.md, secção "Tenancy /
        // Estabelecimento"). Não existe hoje mais de um `Estabelecimento`
        // "activo" possível, por isso este cenário não é simulável sem
        // inventar um mecanismo de tenancy que não existe no resto do
        // sistema. Activar este teste quando a tenancy real (multi-escola)
        // for implementada.
        $this->markTestIncomplete(
            'Isolamento entre tenants ainda não é testável: tenancy real ainda não existe no MosiTec.'
        );
    }
}
