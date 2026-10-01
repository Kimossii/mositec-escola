<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Tenant\Models\Tenant;

trait ComTenantDeTeste
{
    /** Tenant por omissão de cada teste com base de dados. Domínio: localhost. */
    protected ?Tenant $tenant = null;

    /** Contador para dar códigos e domínios únicos aos tenants criados por estabelecimentoDeOutroTenant(). */
    private int $outrosTenants = 0;

    protected function prepararTenantDeTeste(): void
    {
        // Testes sem base de dados (sem RefreshDatabase) não têm tabelas.
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $this->tenant = $this->criarTenant('MOSI-000001', 'Escola de Teste', 'localhost');

        app(TenantContext::class)->definir($this->tenant->paraTenantAtual());

        if (Schema::hasTable('estabelecimentos')) {
            Estabelecimento::current()->forceFill(['configurado_em' => now()])->save();
        }
    }

    protected function criarTenant(string $codigo, string $nome, string $dominio): Tenant
    {
        $tenant = Tenant::create(['codigo' => $codigo, 'nome' => $nome]);
        $tenant->dominios()->create(['dominio' => $dominio, 'is_principal' => true]);

        if (Schema::hasTable('estabelecimentos')) {
            $this->noTenant($tenant, fn () => Estabelecimento::create(['nome' => $nome]));
        }

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

    /**
     * O estabelecimento do tenant por omissão. Os testes que antes criavam "o"
     * estabelecimento passam a pedir este, com os atributos que lhes interessam.
     */
    protected function estabelecimentoDeTeste(array $atributos = []): Estabelecimento
    {
        $estabelecimento = Estabelecimento::current();

        if ($atributos !== []) {
            $estabelecimento->fill($atributos)->save();
        }

        return $estabelecimento;
    }

    /**
     * Um estabelecimento que NÃO é o do tenant corrente. Para os testes que
     * precisam de provar que dados de outro estabelecimento não aparecem.
     */
    protected function estabelecimentoDeOutroTenant(array $atributos = []): Estabelecimento
    {
        $n = ++$this->outrosTenants;
        $outro = $this->criarTenant(sprintf('MOSI-%06d', 900000 + $n), "Outra Escola {$n}", "outra-{$n}.localhost");

        return $this->noTenant($outro, function () use ($atributos) {
            $estabelecimento = Estabelecimento::current();
            $estabelecimento->fill(array_merge(['tipo' => TipoEstabelecimentoEnum::PUBLICO->value], $atributos))->save();

            return $estabelecimento;
        });
    }
}
