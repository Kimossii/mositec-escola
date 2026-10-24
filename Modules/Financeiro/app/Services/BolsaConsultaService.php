<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Financeiro\Enums\EstadoBolsa;
use Modules\Financeiro\Models\Bolsa;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\Multa;

class BolsaConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';

        return Bolsa::query()
            ->with(['aluno.dadosPessoa', 'anoLectivo:id,nome'])
            ->when(is_numeric($filtros['estado'] ?? null), fn ($q) => $q->where('estado', (int) $filtros['estado']))
            ->when(is_numeric($filtros['ano_lectivo_id'] ?? null), fn ($q) => $q->where('ano_lectivo_id', (int) $filtros['ano_lectivo_id']))
            ->when($pesquisa !== '', fn ($q) => $q->where(fn ($g) => $g
                ->whereContem('nome', $pesquisa)
                ->orWhereHas('aluno', fn ($a) => $a->where(fn ($x) => $x
                    ->whereContem('numero_matricula', $pesquisa)
                    ->orWhereHas('dadosPessoa', fn ($p) => $p->whereContem('nome_completo', $pesquisa))))))
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (Bolsa $b) => [
                'id' => $b->id,
                'aluno_id' => $b->aluno_id,
                'aluno_nome' => $b->aluno?->dadosPessoa?->nome_completo,
                'aluno_numero' => $b->aluno?->numero_matricula,
                'ano_lectivo_id' => $b->ano_lectivo_id,
                'ano_lectivo_nome' => $b->anoLectivo?->nome,
                'nome' => $b->nome,
                'percentagem' => Multa::pontosBaseParaPercentagem($b->percentagem),
                'data_inicio' => $b->data_inicio->toDateString(),
                'data_fim' => $b->data_fim->toDateString(),
                'motivo' => $b->motivo,
                'estado' => $b->estado->value,
                'estado_descricao' => $b->estado_descricao,
                'motivo_termino' => $b->motivo_termino,
                'propinas_com_bolsa' => Propina::query()->where('bolsa_id', $b->id)->count(),
            ]);
    }

    /** @return list<array{value: int, label: string}> */
    public function estados(): array
    {
        return EstadoBolsa::opcoes();
    }
}
