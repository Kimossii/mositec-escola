<?php

namespace Modules\PlanoCurricular\DTO;

use Modules\PlanoCurricular\Http\Requests\ConfirmarAnoLectivoRequest;

class ConfirmarAnoLectivoDTO
{
    public function __construct(
        public int $ano_lectivo_id,
        public ?string $observacoes = null,
    ) {
    }

    public static function fromRequest(ConfirmarAnoLectivoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            ano_lectivo_id: (int) $dados['ano_lectivo_id'],
            observacoes: $dados['observacoes'] ?? null,
        );
    }
}
