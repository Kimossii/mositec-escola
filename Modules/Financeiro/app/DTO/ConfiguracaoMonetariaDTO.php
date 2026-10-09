<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;

class ConfiguracaoMonetariaDTO
{
    public function __construct(
        public string $moeda,
        public bool $cambioManual,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            moeda: strtoupper(trim((string) $dados['moeda'])),
            cambioManual: (bool) $dados['cambio_manual'],
        );
    }
}
