<?php

namespace Tests\Concerns;

use Modules\Core\Tenancy\TenantContext;
use Tests\Fixtures\Plataforma\EspiaDoTenantContext;

/**
 * Instala o espião do TenantContext e verifica-o AUTOMATICAMENTE no fim de cada teste: uma violação
 * engolida por um `catch (Throwable)` do código testado não passa despercebida.
 */
trait ComEspiaoDeContexto
{
    private EspiaDoTenantContext $espiao;

    /** @param  string[]  $proibidosExtra */
    protected function instalarEspiaoDeContexto(array $proibidosExtra = []): EspiaDoTenantContext
    {
        $espiao = new EspiaDoTenantContext($proibidosExtra);
        $espiao->herdarDe(app(TenantContext::class));
        $this->app->instance(TenantContext::class, $espiao);

        // Cada instalação regista o seu próprio controlo; o espião anterior (se o houve) também fica verificado.
        $this->beforeApplicationDestroyed(fn () => $espiao->assertNenhumaViolacao());

        return $this->espiao = $espiao;
    }
}
