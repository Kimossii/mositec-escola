<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\BloqueioDePropinas;

/**
 * Anula uma utilização de crédito (spec Pagamentos §6; D15): matrícula da propina → propina → crédito → utilização.
 * O crédito recupera saldo (Esgotado volta a Disponível) e a propina é recalculada.
 */
class AnularUtilizacaoCreditoAction
{
    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private BloqueioDePropinas $bloqueioDePropinas,
        private RecalcularPropina $recalcularPropina,
        private RecalcularCredito $recalcularCredito,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executar(CreditoUtilizacao $utilizacao, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): CreditoUtilizacao
    {
        return DB::transaction(function () use ($utilizacao, $dto, $utilizadorId) {
            $propina = Propina::query()->findOrFail($utilizacao->propina_id);
            $this->bloqueio->matricula((int) $propina->matricula_id, incluirEliminadas: true);
            $this->bloqueioDePropinas->bloquear([(int) $propina->id]);
            $credito = $this->bloqueio->creditos([(int) $utilizacao->credito_id])->first()
                ?? throw ValidationException::withMessages(['utilizacao' => 'O crédito da utilização não existe.']);
            $bloqueada = $this->bloqueio->utilizacao((int) $utilizacao->getKey())
                ?? throw ValidationException::withMessages(['utilizacao' => 'A utilização de crédito não existe.']);

            if ($bloqueada->estado === EstadoUtilizacaoCredito::ANULADA) {
                throw ValidationException::withMessages(['utilizacao' => 'A utilização de crédito já está anulada.']);
            }

            $bloqueada->forceFill([
                'estado' => EstadoUtilizacaoCredito::ANULADA,
                'motivo_anulacao' => $dto->motivo,
                'anulado_por' => $utilizadorId,
                'anulado_em' => $this->relogio->agora()->setTimezone(config('app.timezone')),
            ])->save();

            $this->recalcularCredito->executar($credito);
            $this->recalcularPropina->executar($propina);

            return $bloqueada->fresh();
        });
    }
}
