<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoCobranca;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Support\CacheDeTotais;

/** Relatório financeiro de um período: o que entrou (por origem, método e mês) e a dívida actual (por turma). */
class RelatorioFinanceiroService
{
    public function __construct(private DividasConsultaService $dividas, private CacheDeTotais $cache)
    {
    }

    /** @return array<string, mixed> */
    public function gerar(string $de, string $ate): array
    {
        return $this->cache->lembrar('relatorio', ['de' => $de, 'ate' => $ate], fn () => $this->calcular($de, $ate));
    }

    /** @return array<string, mixed> */
    private function calcular(string $de, string $ate): array
    {
        $confirmado = fn ($q) => $q->where('estado', EstadoPagamento::CONFIRMADO->value)->whereBetween('data_pagamento', [$de, $ate]);

        $pagamentos = Pagamento::query()->with('metodo:id,nome')->where('estado', EstadoPagamento::CONFIRMADO->value)
            ->whereBetween('data_pagamento', [$de, $ate])->get(['id', 'metodo_pagamento_id', 'data_pagamento', 'valor']);

        $porMetodo = $pagamentos->groupBy('metodo_pagamento_id')->map(fn ($g) => [
            'metodo' => $g->first()->metodo?->nome ?? '—',
            'pagamentos' => $g->count(),
            'valor' => (int) $g->sum(fn ($p) => $p->valor->unidadesMenores()),
        ])->sortByDesc('valor')->values()->all();

        $porMes = $pagamentos->groupBy(fn ($p) => substr($p->data_pagamento->toDateString(), 0, 7))->map(fn ($g, $mes) => [
            'mes' => $mes,
            'pagamentos' => $g->count(),
            'valor' => (int) $g->sum(fn ($p) => $p->valor->unidadesMenores()),
        ])->sortKeys()->values()->all();

        $divida = $this->dividas->linhas();

        return [
            'periodo' => ['de' => $de, 'ate' => $ate],
            'recebido' => [
                'total' => (int) $pagamentos->sum(fn ($p) => $p->valor->unidadesMenores()),
                'pagamentos' => $pagamentos->count(),
                'propinas' => (int) PagamentoPropina::query()->whereHas('pagamento', $confirmado)->sum('valor'),
                'multas' => (int) PagamentoMulta::query()->whereHas('pagamento', $confirmado)->sum('valor'),
                'cobrancas' => (int) PagamentoCobranca::query()->whereHas('pagamento', $confirmado)->sum('valor'),
                'credito_gerado' => (int) Credito::query()->whereHas('origem', $confirmado)->sum('valor'),
            ],
            'por_metodo' => $porMetodo,
            'por_mes' => $porMes,
            'divida' => [
                'total' => (int) $divida->sum('total'),
                'propinas' => (int) $divida->sum('propinas'),
                'multas' => (int) $divida->sum('multas'),
                'cobrancas' => (int) $divida->sum('cobrancas'),
                'matriculas' => $divida->count(),
                'por_turma' => $divida->groupBy(fn ($l) => $l['turma_rotulo_completo'] ?? '—')
                    ->map(fn ($g, $turma) => ['turma' => $turma, 'matriculas' => $g->count(), 'total' => (int) $g->sum('total')])
                    ->sortByDesc('total')->values()->all(),
            ],
        ];
    }

    /** @return list<array<string, string|int>> recebimentos confirmados do período, com o valor em unidades menores */
    public function recebimentos(string $de, string $ate): array
    {
        $linhas = [];

        foreach (Pagamento::query()->with(['matricula.aluno.dadosPessoa', 'metodo'])->where('estado', EstadoPagamento::CONFIRMADO->value)
            ->whereBetween('data_pagamento', [$de, $ate])->orderBy('data_pagamento')->orderBy('id')->get() as $p) {
            $linhas[] = [
                'numero' => $p->numero, 'data' => $p->data_pagamento->toDateString(), 'aluno' => $p->matricula?->aluno?->dadosPessoa?->nome_completo ?? '',
                'numero_aluno' => $p->matricula?->aluno?->numero_matricula ?? '', 'matricula' => $p->matricula?->numero_registo_matricula ?? '',
                'metodo' => $p->metodo?->nome ?? '', 'referencia' => $p->referencia ?? '', 'valor' => $p->valor->unidadesMenores(),
            ];
        }

        return $linhas;
    }
}
