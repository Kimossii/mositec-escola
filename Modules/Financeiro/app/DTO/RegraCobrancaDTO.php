<?php

namespace Modules\Financeiro\DTO;

use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Http\Requests\AtualizarRegraCobrancaRequest;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\Multa;

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
        /** null = não mexer na flag */
        public ?bool $multa_activa = null,
        /** @var list<EscalaoMultaDTO>|null null = não mexer nos escalões */
        public ?array $escaloes = null,
    ) {
    }

    public static function fromRequest(AtualizarRegraCobrancaRequest $request): self
    {
        $dados = $request->validated();
        $moeda = app(MoedaDoTenant::class)->atual();

        // A ordem é a posição na lista: o valor enviado pelo cliente nunca é usado.
        $escaloes = null;
        foreach (array_values($dados['escaloes'] ?? []) as $posicao => $escalao) {
            $tipo = TipoMulta::from((int) $escalao['tipo']);
            $valor = $tipo === TipoMulta::PERCENTAGEM
                ? Multa::percentagemParaPontosBase($escalao['valor'])
                : Dinheiro::deDecimal(is_int($escalao['valor']) ? $escalao['valor'] : (string) $escalao['valor'], $moeda)->unidadesMenores();

            $escaloes ??= [];
            $escaloes[] = new EscalaoMultaDTO($posicao + 1, (int) $escalao['dias_atraso'], $tipo, $valor);
        }

        return new self(
            dia_vencimento: (int) $dados['dia_vencimento'],
            dias_tolerancia: (int) $dados['dias_tolerancia'],
            permite_pagamento_parcial: (bool) $dados['permite_pagamento_parcial'],
            permite_pagamento_antecipado: (bool) $dados['permite_pagamento_antecipado'],
            gerar_automaticamente: (bool) $dados['gerar_automaticamente'],
            permite_negociacao: (bool) $dados['permite_negociacao'],
            desconto_maximo_negociacao: (int) $dados['desconto_maximo_negociacao'],
            multa_activa: isset($dados['multa_activa']) ? (bool) $dados['multa_activa'] : null,
            escaloes: $escaloes,
        );
    }
}
