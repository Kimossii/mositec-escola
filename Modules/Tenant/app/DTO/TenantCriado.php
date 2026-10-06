<?php

namespace Modules\Tenant\DTO;

use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;

/**
 * Resultado de CriarTenantAction. A credencial (senha temporária em claro) é entregue
 * uma única vez a quem chamou; não existe em mais lado nenhum.
 */
final readonly class TenantCriado
{
    public function __construct(
        public Tenant $tenant,
        public Domain $dominio,
        public ?CredencialInicial $credencial,
    ) {}
}
