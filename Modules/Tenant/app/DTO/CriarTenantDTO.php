<?php

namespace Modules\Tenant\DTO;

/**
 * Sem palavra-passe: o administrador inicial recebe uma senha temporária gerada pelo provisioning.
 */
final readonly class CriarTenantDTO
{
    public function __construct(
        public string $nomeEstabelecimento,
        public string $nomeAdministrador,
        public string $emailAdministrador,
        public string $dominioPrincipal,
        public ?string $codigo = null,
    ) {}
}
