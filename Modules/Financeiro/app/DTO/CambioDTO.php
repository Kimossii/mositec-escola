<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Support\TaxaCambio;

class CambioDTO
{
    public function __construct(
        public string $data,
        public TaxaCambio $taxa,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            data: $dados['data'],
            taxa: TaxaCambio::deDecimal(is_int($dados['taxa']) ? $dados['taxa'] : (string) $dados['taxa']),
        );
    }
}
