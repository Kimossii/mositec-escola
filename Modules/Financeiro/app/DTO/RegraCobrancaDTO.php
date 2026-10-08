<?php

namespace Modules\Financeiro\DTO;

use Modules\Financeiro\Http\Requests\AtualizarRegraCobrancaRequest;

class RegraCobrancaDTO
{
    public function __construct(
        public int $dia_vencimento,
        public int $dias_tolerancia,
        public bool $permite_pagamento_parcial,
        public bool $permite_pagamento_antecipado,
        public bool $gerar_automaticamente,
        public bool $permite_negociacao,
        public int $desconto_maximo_negociacao,
    ) {
    }

    public static function fromRequest(AtualizarRegraCobrancaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            dia_vencimento: (int) $dados['dia_vencimento'],
            dias_tolerancia: (int) $dados['dias_tolerancia'],
            permite_pagamento_parcial: (bool) $dados['permite_pagamento_parcial'],
            permite_pagamento_antecipado: (bool) $dados['permite_pagamento_antecipado'],
            gerar_automaticamente: (bool) $dados['gerar_automaticamente'],
            permite_negociacao: (bool) $dados['permite_negociacao'],
            desconto_maximo_negociacao: (int) $dados['desconto_maximo_negociacao'],
        );
    }
}
