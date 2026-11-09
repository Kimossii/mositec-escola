<?php

namespace Modules\Core\DTO;

/** O que se regista na auditoria da escola: a acção, a entidade e o seu estado antes e depois. */
readonly class AuditoriaTenantDTO
{
    /**
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $depois
     * @param  ?int  $utilizadorId  nulo = o utilizador em sessão
     */
    public function __construct(
        public string $accao,
        public string $entidadeTipo,
        public ?int $entidadeId,
        public array $antes,
        public array $depois,
        public ?int $utilizadorId = null,
    ) {
    }
}
