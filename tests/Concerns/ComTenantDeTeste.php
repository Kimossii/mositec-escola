<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Models\Tenant;

trait ComTenantDeTeste
{
    /** Tenant por omissão de cada teste com base de dados. Domínio: localhost. */
    protected ?Tenant $tenant = null;

    protected function prepararTenantDeTeste(): void
    {
        // Testes sem base de dados (sem RefreshDatabase) não têm tabelas.
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $this->tenant = $this->criarTenant('MOSI-000001', 'Escola de Teste', 'localhost');

        app(TenantContext::class)->definir($this->tenant->paraTenantAtual());
    }

    protected function criarTenant(string $codigo, string $nome, string $dominio): Tenant
    {
        $tenant = Tenant::create(['codigo' => $codigo, 'nome' => $nome]);
        $tenant->dominios()->create(['dominio' => $dominio, 'is_principal' => true]);

        return $tenant;
    }

    protected function noTenant(Tenant $tenant, Closure $fn): mixed
    {
        return app(TenantContext::class)->executarComo($tenant->paraTenantAtual(), $fn);
    }

    /**
     * Num teste que faz pedidos a mais de um tenant, usar sempre este método:
     * depois de um pedido com URL absoluto, os caminhos relativos ('/x') do
     * mesmo teste herdam o host desse pedido.
     */
    protected function urlDoTenant(Tenant $tenant, string $caminho = '/'): string
    {
        $dominio = $tenant->dominios()->where('is_principal', true)->value('dominio');

        return 'http://' . $dominio . '/' . ltrim($caminho, '/');
    }
}
