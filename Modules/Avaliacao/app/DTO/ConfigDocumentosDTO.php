<?php

namespace Modules\Avaliacao\DTO;

/** O texto do cabeçalho oficial (uma linha por linha de texto), o lema e o visto das pautas da escola. */
readonly class ConfigDocumentosDTO
{
    public function __construct(
        public ?string $linhas_extra = null,
        public ?string $lema = null,
        public ?string $visto_cargo = null,
        public ?string $visto_nome = null,
    ) {
    }
}
