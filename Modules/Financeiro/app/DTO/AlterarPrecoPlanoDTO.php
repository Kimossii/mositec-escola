<?php

namespace Modules\Financeiro\DTO;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Pedido de alteração de preço de um plano (spec §14): novo valor, mês de efeito (1.º dia) e a escolha explícita.
 * Motivo, impressão digital e a confirmação de valores diferentes só existem na confirmação.
 */
final class AlterarPrecoPlanoDTO
{
    public function __construct(
        public readonly Dinheiro $valorNovo,
        public readonly CarbonImmutable $mesEfeito,
        public readonly ModoAlteracaoPreco $modo,
        public readonly ?string $motivo = null,
        public readonly ?string $impressaoDigital = null,
        public readonly bool $confirmaValoresDiferentes = false,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            valorNovo: Dinheiro::deDecimal((string) $dados['valor'], app(MoedaDoTenant::class)->atual()),
            mesEfeito: CarbonImmutable::createFromFormat('!Y-m', (string) $dados['mes_efeito']),
            modo: ModoAlteracaoPreco::from((int) $dados['modo']),
            motivo: isset($dados['motivo']) ? trim((string) $dados['motivo']) : null,
            impressaoDigital: $dados['impressao_digital'] ?? null,
            confirmaValoresDiferentes: (bool) ($dados['confirmar_valores_diferentes'] ?? false),
        );
    }
}
