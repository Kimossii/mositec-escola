<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Actions\AplicarBolsaAction;
use Modules\Financeiro\Actions\GuardarBolsaAction;
use Modules\Financeiro\Actions\TerminarBolsaAction;
use Modules\Financeiro\DTO\GuardarBolsaDTO;
use Modules\Financeiro\Http\Requests\GuardarBolsaRequest;
use Modules\Financeiro\Http\Requests\TerminarBolsaRequest;
use Modules\Financeiro\Models\Bolsa;

/** Os pedidos de escrita das bolsas de estudo: monta o DTO a partir do pedido e entrega à Action. */
class GestaoBolsaService
{
    public function __construct(
        private GuardarBolsaAction $guardar,
        private TerminarBolsaAction $terminar,
        private AplicarBolsaAction $aplicar,
    ) {
    }

    /** Cria a bolsa, ou actualiza a recebida. */
    public function guardar(GuardarBolsaRequest $request, ?Bolsa $bolsa = null): Bolsa
    {
        $d = $request->validated();

        return $this->guardar->executar(new GuardarBolsaDTO(
            nome: $d['nome'],
            percentagem: (string) $d['percentagem'],
            dataInicio: $d['data_inicio'],
            dataFim: $d['data_fim'],
            motivo: $d['motivo'],
            matriculaId: isset($d['matricula_id']) ? (int) $d['matricula_id'] : null,
        ), $bolsa);
    }

    public function terminar(Bolsa $bolsa, TerminarBolsaRequest $request): void
    {
        $this->terminar->executar($bolsa, $request->validated()['motivo']);
    }

    /** @return array{aplicadas: int, ignoradas: int} */
    public function aplicar(Bolsa $bolsa): array
    {
        return $this->aplicar->executar($bolsa);
    }
}
