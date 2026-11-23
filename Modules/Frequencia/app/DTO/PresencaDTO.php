<?php

namespace Modules\Frequencia\DTO;

/** A presença de um aluno numa chamada, já validada quanto ao formato (`estado` é o valor de `EstadoPresenca`). */
final readonly class PresencaDTO
{
    public function __construct(
        public int $matricula_id,
        public int $estado,
        public ?string $observacao = null,
    ) {
    }
}
