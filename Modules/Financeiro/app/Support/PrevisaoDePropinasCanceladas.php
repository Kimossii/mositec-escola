<?php

namespace Modules\Financeiro\Support;

use Modules\Core\Contracts\PrevisaoCancelamentoAoTerminarMatricula;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;

/** As propinas que o fim da matrícula nessa data cancelaria (mesma regra do cancelamento real), só leitura. */
class PrevisaoDePropinasCanceladas implements PrevisaoCancelamentoAoTerminarMatricula
{
    public function __construct(private CancelamentoDePropinas $cancelamento, private RelogioDoTenant $relogio)
    {
    }

    public function prever(int $matriculaId, string $dataEfeito): array
    {
        $hoje = $this->relogio->hoje()->toDateString();

        return Propina::query()
            ->where('matricula_id', $matriculaId)
            ->where('estado', EstadoCobranca::EM_ABERTO->value)
            ->where('periodo_inicio', '>', $dataEfeito)
            ->orderBy('periodo_inicio')
            ->get()
            ->filter(fn (Propina $propina) => $this->cancelamento->motivoParaNaoCancelar($propina) === null)
            ->map(fn (Propina $propina) => [
                'descricao' => RotuloDePeriodo::periodo($propina->periodo_inicio->toDateString(), $propina->periodo_fim->toDateString()),
                'vencida' => $propina->data_limite !== null && $propina->data_limite->toDateString() < $hoje,
            ])
            ->values()
            ->all();
    }
}
