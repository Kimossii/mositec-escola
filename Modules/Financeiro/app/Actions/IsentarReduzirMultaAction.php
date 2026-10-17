<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Financeiro\DTO\IsentarMultaDTO;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Enums\OrigemRevisaoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\PropinaMultaRevisao;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Isenção/redução manual de uma multa (spec Propinas §15.4, `propina.isentar-multa`). Bloqueia a propina e a multa
 * antes de validar; `novo_valor == valor_pago` ⇒ ISENTA (pode ser 0), senão REDUZIDA; põe `bloqueada_automatico` e
 * regista a revisão MANUAL (motivo e autor). A multa ANULADA é recusada.
 */
class IsentarReduzirMultaAction
{
    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function executar(Propina $propina, IsentarMultaDTO $dto, ?int $utilizadorId): PropinaMulta
    {
        return DB::transaction(function () use ($propina, $dto, $utilizadorId) {
            $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
                ?? throw new LogicException('A propina a isentar não existe no tenant corrente.');

            $multa = $bloqueada->multa()->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['multa' => 'Esta propina não tem multa.']);

            if ($multa->estado === EstadoMulta::ANULADA) {
                throw ValidationException::withMessages(['multa' => 'Uma multa anulada não pode ser isentada nem reduzida.']);
            }

            $valorPago = $multa->valor_pago->unidadesMenores();
            $valorActual = $multa->valor->unidadesMenores();
            $novo = $dto->novoValor->unidadesMenores();

            if ($novo < $valorPago || $novo > $valorActual) {
                throw ValidationException::withMessages(['novo_valor' => 'O novo valor tem de estar entre o já pago (' . $this->formatar($valorPago) . ') e o valor actual (' . $this->formatar($valorActual) . ').']);
            }

            $multa->forceFill([
                'valor' => Dinheiro::deUnidadesMenores($novo),
                'estado' => $novo === $valorPago ? EstadoMulta::ISENTA : EstadoMulta::REDUZIDA,
                'bloqueada_automatico' => true,
            ])->save();

            $revisao = new PropinaMultaRevisao([
                'propina_multa_id' => $multa->id,
                'origem' => OrigemRevisaoMulta::MANUAL,
                'valor_anterior' => $valorActual,
                'valor_novo' => $novo,
                'motivo' => $dto->motivo,
            ]);
            $revisao->criado_por = $utilizadorId;
            $revisao->save();

            return $multa->fresh();
        });
    }

    private function formatar(int $unidades): string
    {
        return Dinheiro::deUnidadesMenores($unidades)->formatar($this->moedaDoTenant->atual());
    }
}
