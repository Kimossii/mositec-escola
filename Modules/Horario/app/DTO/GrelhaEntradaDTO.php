<?php

namespace Modules\Horario\DTO;

/** Dados de uma entrada da grelha, já validados quanto ao formato. `ano_lectivo_id` nunca vem do cliente. */
final readonly class GrelhaEntradaDTO
{
    public function __construct(
        public int $turma_id,
        public int $dia_semana,
        public int $horario_id,
        public int $disciplina_id,
        public ?int $sala_id,
        public string $vigencia_inicio,
        public ?string $vigencia_fim,
    ) {
    }
}
