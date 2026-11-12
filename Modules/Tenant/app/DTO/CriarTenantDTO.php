<?php

namespace Modules\Tenant\DTO;

/**
 * Sem palavra-passe: o administrador inicial recebe uma senha temporária gerada pelo provisioning.
 *
 * `dominioPrincipal` é obrigatório no modo `dominio` (a Action recusa a falta). No modo `unico` pode faltar: a escola usa
 * o host de `APP_URL` como domínio de referência técnica.
 */
final readonly class CriarTenantDTO
{
    public function __construct(
        public string $nomeEstabelecimento,
        public string $nomeAdministrador,
        public string $emailAdministrador,
        public ?string $dominioPrincipal = null,
        public ?string $codigo = null,
    ) {}
}
