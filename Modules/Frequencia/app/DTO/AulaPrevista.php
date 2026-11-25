<?php

namespace Modules\Frequencia\DTO;

use Modules\Frequencia\Enums\TipoAula;

/**
 * Uma aula que a grelha e o calendário dizem que devia acontecer (calculada na hora, nunca guardada). No regime «dia»
 * não há tempo, disciplina nem sala; `docente_id` é o titular em vigor nesse dia (da disciplina, no regime tempo).
 */
final readonly class AulaPrevista
{
    public function __construct(
        public int $turma_id,
        public string $data,
        public TipoAula $tipo,
        public ?int $horario_id,
        public ?int $disciplina_id,
        public ?int $sala_id,
        public ?int $docente_id,
        public bool $sem_docente,
    ) {
    }
}
