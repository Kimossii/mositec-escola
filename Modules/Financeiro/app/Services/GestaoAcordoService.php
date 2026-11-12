<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Actions\CriarAcordoAction;
use Modules\Financeiro\Actions\EncerrarAcordoAction;
use Modules\Financeiro\DTO\CriarAcordoDTO;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Financeiro\Http\Requests\CriarAcordoRequest;
use Modules\Financeiro\Http\Requests\EncerrarAcordoRequest;
use Modules\Financeiro\Models\Acordo;
use Modules\Matricula\Models\Matricula;

/** Os pedidos de escrita dos acordos de pagamento: monta o DTO a partir do pedido e entrega à Action. */
class GestaoAcordoService
{
    public function __construct(private CriarAcordoAction $criar, private EncerrarAcordoAction $encerrar)
    {
    }

    public function criar(CriarAcordoRequest $request, ?int $utilizadorId): Acordo
    {
        $d = $request->validated();

        return $this->criar->executar(
            Matricula::query()->findOrFail((int) $d['matricula_id']),
            new CriarAcordoDTO(
                propinaIds: array_map('intval', $d['propinas']),
                descontoMultaPct: (int) $d['desconto_multa_pct'],
                prestacoes: (int) $d['prestacoes'],
                primeiroVencimento: $d['primeiro_vencimento'],
                motivo: $d['motivo'],
                utilizadorId: $utilizadorId,
            ),
        );
    }

    public function encerrar(Acordo $acordo, EncerrarAcordoRequest $request): void
    {
        $this->encerrar->executar($acordo, $request->quebra() ? EstadoAcordo::QUEBRADO : EstadoAcordo::CANCELADO, $request->validated()['motivo']);
    }
}
