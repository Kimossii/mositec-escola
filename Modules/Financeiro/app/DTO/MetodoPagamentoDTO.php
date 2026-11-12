<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Enums\TipoMetodoPagamento;

class MetodoPagamentoDTO
{
    public function __construct(
        public readonly string $nome,
        public readonly TipoMetodoPagamento $tipo,
        public readonly ?string $titular,
        public readonly ?string $banco,
        public readonly ?string $iban,
        public readonly ?string $numeroConta,
        public readonly ?string $telefone,
        public readonly bool $exigeComprovativo,
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
