<?php

namespace Modules\Avaliacao\DTO;

/** O que se pode mudar numa avaliação já criada: o nome e se é obrigatória. Turma, disciplina, trimestre e tipo são definitivos. */
readonly class AtualizarInstrumentoDTO
{
    public function __construct(
        public int $instrumento_id,
        public string $nome,
        public bool $obrigatorio,
    ) {
    }
}
