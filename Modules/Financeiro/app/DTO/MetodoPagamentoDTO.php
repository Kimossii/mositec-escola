<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Enums\TipoMetodoPagamento;

class MetodoPagamentoDTO
{
    public function __construct(
        public string $nome,
        public TipoMetodoPagamento $tipo,
        public ?string $titular,
        public ?string $banco,
        public ?string $iban,
        public ?string $numeroConta,
        public ?string $telefone,
        public bool $exigeComprovativo,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            tipo: TipoMetodoPagamento::from((int) $dados['tipo']),
            titular: $dados['titular'] ?? null,
            banco: $dados['banco'] ?? null,
            iban: $dados['iban'] ?? null,
            numeroConta: $dados['numero_conta'] ?? null,
            telefone: $dados['telefone'] ?? null,
            exigeComprovativo: (bool) ($dados['exige_comprovativo'] ?? false),
        );
    }
}
