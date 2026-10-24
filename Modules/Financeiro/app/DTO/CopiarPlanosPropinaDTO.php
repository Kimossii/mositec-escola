<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;

class CopiarPlanosPropinaDTO
{
    public function __construct(
        public int $ano_origem_id,
        public int $ano_destino_id,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            ano_origem_id: (int) $dados['ano_origem_id'],
            ano_destino_id: (int) $dados['ano_destino_id'],
        );
    }
}
