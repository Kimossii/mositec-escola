<?php

namespace Modules\Core\Comunicacao\Email;

/** Regras de email da subscrição da escola: quota mensal de opcionais (null = sem limite), % de aviso e se bloqueia ao exceder. */
final readonly class RegrasDeEmail
{
    public function __construct(
        public ?int $limiteMes = null,
        public int $avisoPct = 80,
        public bool $bloqueia = true,
    ) {}
}
