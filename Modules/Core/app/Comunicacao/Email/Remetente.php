<?php

namespace Modules\Core\Comunicacao\Email;

final readonly class Remetente
{
    public function __construct(
        public string $nome,
        public string $endereco,
        public ?string $replyTo = null,
    ) {}
}
