<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;

/** Usar crédito do aluno sem dinheiro novo (D12). */
final class AplicarCreditoDTO
{
    /** @var list<LinhaDeDistribuicao>|null */
    public readonly ?array $distribuicao;

    /**
     * @param  list<LinhaDeDistribuicao>|null  $distribuicao
     */
    public function __construct(
        public readonly Dinheiro $valorCredito,
        ?array $distribuicao = null,
    ) {
        if ($valorCredito->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('O crédito a aplicar tem de ser maior que zero.');
        }

        $this->distribuicao = $distribuicao === null ? null : LinhaDeDistribuicao::lista($distribuicao);
    }

    public static function fromRequest(FormRequest $request): self
    {
        $moeda = app(MoedaDoTenant::class)->atual();

        return new self(
            Dinheiro::deDecimal((string) $request->validated('valor_credito'), $moeda),
            LinhaDeDistribuicao::doPedido((string) $request->validated('modo_distribuicao'), $request->validated('distribuicao') ?? [], $moeda),
        );
    }
}
