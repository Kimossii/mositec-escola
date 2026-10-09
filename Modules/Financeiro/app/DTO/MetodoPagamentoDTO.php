<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Enums\TipoMetodoPagamento;

class MetodoPagamentoDTO
{
    public function __construct(
        public string $nome,
        public TipoMetodoPagamento $tipo,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            tipo: TipoMetodoPagamento::from((int) $dados['tipo']),
        );
    }
}
