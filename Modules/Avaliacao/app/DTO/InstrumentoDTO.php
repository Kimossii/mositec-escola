<?php

namespace Modules\Avaliacao\DTO;

use Modules\Avaliacao\Enums\TipoInstrumento;

readonly class InstrumentoDTO
{
    public function __construct(
        public int $turma_id,
        public int $disciplina_id,
        public int $periodo_id,
        public TipoInstrumento $tipo,
        public string $nome,
        public bool $obrigatorio = true,
    ) {
    }
}
