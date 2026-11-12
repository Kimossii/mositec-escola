<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Instalação dedicada (`TENANCY_MODO=unico`): um só domínio serve a escola e o painel da Plataforma em `/plataforma`.
 * Em modo `dominio` o painel continua restrito aos hosts centrais.
 */
class InstalacaoDedicadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_modo_unico_o_painel_abre_no_mesmo_dominio_mesmo_sem_nenhuma_escola(): void
    {
        config(['tenancy.modo' => 'unico', 'tenancy.hosts_centrais' => []]);

        // Antes de existir a primeira escola é preciso poder criá-la pelo painel.
        $this->get('http://cliente.exemplo.test/plataforma/login')->assertOk();
    }

    public function test_no_modo_dominio_o_painel_continua_so_nos_hosts_centrais(): void
    {
        config(['tenancy.modo' => 'dominio', 'tenancy.hosts_centrais' => ['painel.exemplo.test']]);

        $this->get('http://cliente.exemplo.test/plataforma/login')->assertNotFound();
        $this->get('http://painel.exemplo.test/plataforma/login')->assertOk();
    }
}
