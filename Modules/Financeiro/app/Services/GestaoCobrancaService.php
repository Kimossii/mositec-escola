<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Actions\CancelarCobrancaAction;
use Modules\Financeiro\Actions\CriarCobrancaAction;
use Modules\Financeiro\Actions\PagarCobrancaAction;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Http\Requests\CriarCobrancaRequest;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Matricula\Models\Matricula;

/** Orquestra criar (e opcionalmente pagar de seguida), pagar e cancelar uma cobrança. */
class GestaoCobrancaService
{
    public function __construct(
        private CriarCobrancaAction $criar,
        private PagarCobrancaAction $pagar,
        private CancelarCobrancaAction $cancelar,
    ) {
    }

    /** @return array{cobranca: Cobranca, pagamento: ?Pagamento} */
    public function criar(CriarCobrancaRequest $request, ?int $utilizadorId): array
    {
        $dados = $request->validated();
        $matricula = Matricula::query()->findOrFail($dados['matricula_id']);

        // Criar e pagar na mesma transacção: se o pagamento falhar, a cobrança (e o stock abatido) revertem.
        return DB::transaction(function () use ($request, $dados, $matricula, $utilizadorId) {
            $cobranca = $this->criar->executar($matricula, $dados['itens'], $dados['data_limite'] ?? null, $dados['observacao'] ?? null, utilizadorId: $utilizadorId);
            $pagamento = ($dados['pagar_agora'] ?? false) ? $this->pagar($cobranca, $request) : null;

            return ['cobranca' => $cobranca, 'pagamento' => $pagamento];
        });
    }

    public function pagar(Cobranca $cobranca, Request $request): Pagamento
    {
        $dto = new RegistarPagamentoDTO(
            metodoPagamentoId: (int) $request->input('metodo_pagamento_id'),
            valor: Dinheiro::deUnidadesMenores(max(1, $cobranca->saldo())),
            dataPagamento: CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $request->input('data_pagamento'), 0, 10)),
            referencia: $request->input('referencia') !== null && $request->input('referencia') !== '' ? (string) $request->input('referencia') : null,
            observacao: null,
            comprovativo: $request->file('comprovativo'),
        );

        return $this->pagar->executar($cobranca, $dto);
    }

    public function cancelar(Cobranca $cobranca, string $motivo, ?int $utilizadorId): Cobranca
    {
        return $this->cancelar->executar($cobranca, $motivo, $utilizadorId);
    }
}
