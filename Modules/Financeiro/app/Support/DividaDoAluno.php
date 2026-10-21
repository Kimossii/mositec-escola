<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Matricula\Models\Matricula;

/**
 * Dívida exigível de um aluno em TODAS as suas matrículas, em unidades menores: propinas Em Aberto ou
 * Parcialmente Pagas de períodos já iniciados, multas por liquidar e cobranças (produtos e serviços) em aberto.
 */
class DividaDoAluno
{
    /** @return array{propinas: int, multas: int, cobrancas: int, total: int} */
    public function de(int $alunoId, string $hoje, ?int $ignorarCobrancaId = null): array
    {
        $matriculas = Matricula::query()->where('aluno_id', $alunoId)->select('id');

        $propinas = (int) Propina::query()
            ->whereIn('matricula_id', $matriculas)
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->where('periodo_inicio', '<=', $hoje)
            ->selectRaw('COALESCE(SUM(valor - valor_pago), 0) as soma')->value('soma');

        $multas = (int) PropinaMulta::query()
            ->whereIn('estado', [EstadoMulta::ACTIVA->value, EstadoMulta::REDUZIDA->value])
            ->whereIn('propina_id', Propina::query()->whereIn('matricula_id', $matriculas)->select('id'))
            ->selectRaw('COALESCE(SUM(valor - valor_pago), 0) as soma')->value('soma');

        $cobrancas = (int) Cobranca::query()
            ->where('aluno_id', $alunoId)
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->when($ignorarCobrancaId !== null, fn ($q) => $q->where('id', '!=', $ignorarCobrancaId))
            ->selectRaw('COALESCE(SUM(total - valor_pago), 0) as soma')->value('soma');

        return ['propinas' => $propinas, 'multas' => $multas, 'cobrancas' => $cobrancas, 'total' => $propinas + $multas + $cobrancas];
    }
}
