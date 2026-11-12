<?php

namespace Modules\Plataforma\DTO;

use Modules\Plataforma\Models\SuperAdmin;

/** Um registo da auditoria da plataforma: quem fez (nulo = o sistema), o quê e a que escola diz respeito. */
readonly class RegistoAuditoriaDTO
{
    /** @param  array<string, mixed>  $detalhe  nunca leva segredos: a Action recusa-os */
    public function __construct(
        public ?SuperAdmin $autor,
        public string $accao,
        public ?string $codigoTenant,
        public array $detalhe = [],
    ) {
    }
}
