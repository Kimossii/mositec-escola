<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Enums\EstadoBolsa;
use Modules\Financeiro\Models\Bolsa;

/** Que bolsa activa cobre um período de um aluno (memoriza por aluno e ano lectivo durante o pedido). */
class BolsasDoAluno
{
    /** @var array<string, list<Bolsa>> */
    private array $memoria = [];

    public function paraPeriodo(int $alunoId, int $anoLectivoId, string $periodoInicio): ?Bolsa
    {
        $chave = "{$alunoId}-{$anoLectivoId}";
        $this->memoria[$chave] ??= Bolsa::query()
            ->where('aluno_id', $alunoId)->where('ano_lectivo_id', $anoLectivoId)->where('estado', EstadoBolsa::ACTIVA->value)
            ->orderByDesc('percentagem')->get()->all();

        foreach ($this->memoria[$chave] as $bolsa) {
            if ($bolsa->data_inicio->toDateString() <= $periodoInicio && $periodoInicio <= $bolsa->data_fim->toDateString()) {
                return $bolsa;
            }
        }

        return null;
    }

    public function esquecer(): void
    {
        $this->memoria = [];
    }
}
