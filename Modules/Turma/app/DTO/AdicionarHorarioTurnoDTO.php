<?php

namespace Modules\Turma\DTO;

use Modules\Turma\Http\Requests\AdicionarHorarioTurnoRequest;

class AdicionarHorarioTurnoDTO
{
    public function __construct(
        public int $horario_id,
        public int $ordem,
    ) {
    }

    public static function fromRequest(
        AdicionarHorarioTurnoRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            horario_id: (int) $dados['horario_id'],
            ordem: (int) $dados['ordem'],
        );
    }
}
