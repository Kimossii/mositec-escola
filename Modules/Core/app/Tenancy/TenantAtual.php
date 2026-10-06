<?php

namespace Modules\Core\Tenancy;

use Modules\Core\Tenancy\Enums\EstadoTenant;

/**
 * Tudo o que os módulos da escola conhecem do tenant corrente.
 * Não é um model: não depende das tabelas de gestão de tenants.
 */
final readonly class TenantAtual
{
    public function __construct(
        public int $id,
        public string $codigo,
        public string $nome,
        public EstadoTenant $estado,
    ) {}
}
