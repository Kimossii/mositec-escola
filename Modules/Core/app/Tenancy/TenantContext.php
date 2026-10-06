<?php

namespace Modules\Core\Tenancy;

use Closure;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;

class TenantContext
{
    private ?TenantAtual $tenant = null;

    /** @var array<string, mixed> */
    private array $memoria = [];

    public function atual(): TenantAtual
    {
        return $this->tenant ?? throw new TenantNaoResolvido();
    }

    public function id(): int
    {
        return $this->atual()->id;
    }

    public function temTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function definir(TenantAtual $tenant): void
    {
        $this->tenant = $tenant;
        $this->memoria = [];
    }

    public function limpar(): void
    {
        $this->tenant = null;
        $this->memoria = [];
    }

    /**
     * Único caminho para ter contexto fora de um pedido HTTP
     * (provisioning, seeders, testes, comandos, jobs).
     */
    public function executarComo(TenantAtual $tenant, Closure $fn): mixed
    {
        $tenantAnterior = $this->tenant;
        $memoriaAnterior = $this->memoria;

        $this->definir($tenant);

        try {
            return $fn($tenant);
        } finally {
            $this->tenant = $tenantAnterior;
            $this->memoria = $memoriaAnterior;
        }
    }

    /**
     * Memória por tenant e por pedido. Esvaziada quando o tenant muda.
     */
    public function lembrar(string $chave, Closure $fn): mixed
    {
        $this->atual();

        if (! array_key_exists($chave, $this->memoria)) {
            $this->memoria[$chave] = $fn();
        }

        return $this->memoria[$chave];
    }
}
