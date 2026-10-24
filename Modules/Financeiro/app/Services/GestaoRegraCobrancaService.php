<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Actions\AtualizarRegraCobrancaAction;
use Modules\Financeiro\DTO\RegraCobrancaDTO;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Http\Requests\AtualizarRegraCobrancaRequest;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\Multa;

class GestaoRegraCobrancaService
{
    public function __construct(
        private AtualizarRegraCobrancaAction $atualizarAction,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function obterAtual(): RegraCobranca
    {
        return RegraCobranca::doTenant();
    }

    public function atualizar(AtualizarRegraCobrancaRequest $request): RegraCobranca
    {
        return $this->atualizarAction->executar(RegraCobrancaDTO::fromRequest($request));
    }

    public function moeda(): array
    {
        return $this->moedaDoTenant->atual()->paraFrontend();
    }

    /**
     * Escalões prontos para editar: percentagem como texto ("2,5") e valor fixo em decimal da moeda.
     *
     * @return list<array{ordem: int, dias_atraso: int, tipo: int, valor_input: string}>
     */
    public function escaloesParaEdicao(RegraCobranca $regra): array
    {
        $moeda = $this->moedaDoTenant->atual();

        return $regra->escaloes()->get()->map(fn ($escalao) => [
            'ordem' => $escalao->ordem,
            'dias_atraso' => $escalao->dias_atraso,
            'tipo' => $escalao->tipo->value,
            'valor_input' => $escalao->tipo === TipoMulta::PERCENTAGEM
                ? Multa::pontosBaseParaPercentagem($escalao->valor)
                : Dinheiro::deUnidadesMenores($escalao->valor)->paraDecimal($moeda),
        ])->all();
    }
}
