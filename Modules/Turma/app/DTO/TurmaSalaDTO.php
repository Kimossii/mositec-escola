<?php

namespace Modules\Turma\DTO;

use Modules\Turma\Http\Requests\AssociarSalaTurmaRequest;
use Modules\Turma\Http\Requests\EncerrarSalaTurmaRequest;

class TurmaSalaDTO
{
    public function __construct(
        public ?int $sala_id = null,
        public ?string $inicio = null,
        public ?string $fim = null,
    ) {
    }

    public static function fromAssociarRequest(
        AssociarSalaTurmaRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            sala_id: (int) $dados['sala_id'],
            inicio: $dados['inicio'],
        );
    }

    public static function fromEncerrarRequest(
        EncerrarSalaTurmaRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            fim: $dados['fim'],
        );
    }
}
