<?php

namespace Modules\PlanoCurricular\DTO;

use Modules\PlanoCurricular\Http\Requests\DefinirPeriodosDisciplinaRequest;

class DefinirPeriodosDisciplinaDTO
{
    public function __construct(
        public array $periodo_ids,
    ) {
    }

    public static function fromRequest(DefinirPeriodosDisciplinaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            periodo_ids: array_map('intval', $dados['periodo_ids'] ?? []),
        );
    }
}
