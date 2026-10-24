<?php

namespace Modules\Financeiro\Support;

use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Financeiro\Models\Acordo;
use Modules\Financeiro\Models\Propina;

/**
 * Quanto do acordo já está pago e o estado de cada prestação, tudo derivado do que falta pagar nas propinas incluídas:
 * pago = total − em falta. A prestação i está coberta quando o pago chega à soma das prestações 1..i; fica em atraso se
 * venceu e não está coberta. Sem nada em falta o acordo está cumprido.
 */
class ProgressoDoAcordo
{
    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    /** @return array<string, mixed> */
    public function de(Acordo $acordo): array
    {
        $acordo->loadMissing(['prestacoes', 'propinas']);
        $hoje = $this->relogio->hoje()->toDateString();
        $total = $acordo->total->unidadesMenores();

        $emFalta = (int) Propina::query()->with('multa')->whereIn('id', $acordo->propinas->pluck('propina_id'))->get()
            ->sum(fn (Propina $p) => $p->saldo()->unidadesMenores() + $p->multaPendente());
        $emFalta = min($emFalta, $total);
        $pago = $total - $emFalta;

        $acumulado = 0;
        $prestacoes = [];
        $proxima = null;
        $emAtraso = false;

        foreach ($acordo->prestacoes as $prestacao) {
            $acumulado += $prestacao->valor->unidadesMenores();
            $coberta = $pago >= $acumulado;
            $vencida = ! $coberta && $prestacao->data_vencimento->toDateString() < $hoje;
            $emAtraso = $emAtraso || $vencida;

            $linha = [
                'ordem' => $prestacao->ordem,
                'data_vencimento' => $prestacao->data_vencimento->toDateString(),
                'valor' => $prestacao->valor->unidadesMenores(),
                'estado' => $coberta ? 'paga' : ($vencida ? 'em_atraso' : 'pendente'),
            ];
            $prestacoes[] = $linha;

            if (! $coberta && $proxima === null) {
                $proxima = $linha + ['em_falta' => $acumulado - $pago];
            }
        }

        $cumprido = $emFalta === 0;
        $activo = $acordo->estado === EstadoAcordo::ACTIVO;

        return [
            'total' => $total,
            'pago' => $pago,
            'em_falta' => $emFalta,
            'prestacoes' => $prestacoes,
            'proxima' => $activo && ! $cumprido ? $proxima : null,
            'em_atraso' => $activo && ! $cumprido && $emAtraso,
            'cumprido' => $cumprido,
            'estado_efectivo' => $activo ? ($cumprido ? 'Cumprido' : ($emAtraso ? 'Em atraso' : 'Activo')) : $acordo->estado->label(),
        ];
    }
}
