<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\AplicarCreditoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Matricula\Models\Matricula;

/**
 * Usa crédito do aluno em propinas de uma matrícula dele, sem dinheiro novo (spec Pagamentos §4, §8; D12). Mesma
 * ordem de bloqueio e mesmo motor do registo; data efectiva = hoje da escola. Não cria pagamento nem número.
 */
class AplicarCreditoAction
{
    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private BloqueioDePropinas $bloqueioDePropinas,
        private DistribuicaoDePagamentoService $distribuicao,
        private RecalcularPropina $recalcularPropina,
        private RecalcularCredito $recalcularCredito,
        private RelogioDoTenant $relogio,
    ) {
    }

    /**
     * @return list<CreditoUtilizacao>
     */
    public function executar(Matricula $matricula, AplicarCreditoDTO $dto): array
    {
        return DB::transaction(function () use ($matricula, $dto) {
            $bloqueada = $this->bloqueio->matricula((int) $matricula->getKey())
                ?? throw ValidationException::withMessages(['matricula' => 'A matrícula não existe ou foi eliminada.']);

            // Em Aberto e Parcialmente Paga todas; Paga só as que têm multa pendente (valor > valor_pago).
            $propinas = $this->bloqueioDePropinas->bloquear(
                Propina::query()
                    ->where('matricula_id', $bloqueada->id)
                    ->where(function (Builder $q) {
                        $q->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
                            ->orWhere(function (Builder $sub) {
                                $sub->where('estado', EstadoCobranca::PAGA->value)
                                    ->whereHas('multa', fn (Builder $m) => $m
                                        ->where('estado', '!=', EstadoMulta::ANULADA->value)
                                        ->whereColumn('propina_multas.valor', '>', 'propina_multas.valor_pago'));
                            });
                    })
                    ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );
            $creditos = $this->bloqueio->creditos(
                Credito::query()->where('aluno_id', $bloqueada->aluno_id)->where('estado', EstadoCredito::DISPONIVEL->value)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );
            $hoje = $this->relogio->hoje();

            $plano = $this->distribuicao->calcular(
                0,
                $dto->valorCredito->unidadesMenores(),
                $hoje,
                $dto->distribuicao,
                $propinas,
                $creditos,
                RegraCobranca::doTenant(),
            );

            $criadas = [];

            foreach ($plano->utilizacoes as $utilizacao) {
                $criadas[] = CreditoUtilizacao::create([
                    'credito_id' => $utilizacao['credito_id'],
                    'propina_id' => $utilizacao['propina_id'],
                    'propina_multa_id' => $utilizacao['propina_multa_id'],
                    'valor' => Dinheiro::deUnidadesMenores($utilizacao['valor']),
                    'data_utilizacao' => $hoje->toDateString(),
                ]);
            }

            $this->recalcularPropina->executarVarias($plano->propinasTocadas());

            foreach ($creditos->whereIn('id', $plano->creditosUsados()) as $credito) {
                $this->recalcularCredito->executar($credito);
            }

            return array_map(fn (CreditoUtilizacao $u) => $u->fresh(), $criadas);
        });
    }
}
