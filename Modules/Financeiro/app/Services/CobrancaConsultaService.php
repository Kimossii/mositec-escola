<?php

namespace Modules\Financeiro\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemCobranca;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\CacheDeTotais;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

/** Leituras das cobranças de produtos e serviços (lista, detalhe, pesquisa de matrículas e catálogo para venda). */
class CobrancaConsultaService
{
    public function __construct(private CacheDeTotais $totais)
    {
    }

    /** Cobranças que cumprem os filtros (sem estado quando `$comEstado` é falso: os indicadores mostram todos). */
    private function base(array $filtros, bool $comEstado): Builder
    {
        $estado = $filtros['estado'] ?? '';
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';

        return Cobranca::query()
            ->when($comEstado && $estado !== '' && $estado !== null, fn ($q) => $q->where('estado', (int) $estado))
            ->when(($filtros['origem'] ?? '') !== '', fn ($q) => $q->where('origem', (int) $filtros['origem']))
            ->when($pesquisa !== '', fn ($q) => $q->where(fn ($g) => $g
                ->whereHas('matricula', fn ($m) => $m->whereContem('numero_registo_matricula', $pesquisa))
                ->orWhereHas('aluno', fn ($a) => $a->where(fn ($x) => $x
                    ->whereContem('numero_matricula', $pesquisa)
                    ->orWhereHas('dadosPessoa', fn ($p) => $p->whereContem('nome_completo', $pesquisa))))));
    }

    /**
     * Números para os cartões do topo: por pagar, pagas, obrigatórias por pagar e canceladas. Unidades menores.
     *
     * @return array<string, int>
     */
    public function indicadores(array $filtros = []): array
    {
        return $this->totais->lembrar('cobrancas', $filtros, function () use ($filtros) {
            $agregado = fn (array $estados, ?int $origem = null) => $this->base($filtros, false)->whereIn('estado', $estados)
                ->when($origem !== null, fn ($q) => $q->where('origem', $origem))
                ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as emitido, COALESCE(SUM(total - valor_pago), 0) as saldo')->first();
            $abertas = $agregado([EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value]);
            $pagas = $agregado([EstadoCobranca::PAGA->value]);
            $obrigatorias = $agregado([EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value], OrigemCobranca::OBRIGATORIO->value);
            $canceladas = $agregado([EstadoCobranca::CANCELADA->value]);

            return [
                'abertas_n' => (int) $abertas->n, 'abertas_valor' => (int) $abertas->saldo,
                'pagas_n' => (int) $pagas->n, 'pagas_valor' => (int) $pagas->emitido,
                'obrigatorias_n' => (int) $obrigatorias->n, 'obrigatorias_valor' => (int) $obrigatorias->saldo,
                'canceladas_n' => (int) $canceladas->n, 'canceladas_valor' => (int) $canceladas->emitido,
            ];
        });
    }

    public function listar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        return $this->base($filtros, true)
            ->with(['aluno.dadosPessoa', 'matricula:id,numero_registo_matricula'])
            ->withCount('itens')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (Cobranca $c) => $this->linha($c));
    }

    /** @return array<string, mixed> */
    public function detalhe(Cobranca $cobranca): array
    {
        $cobranca->load(['aluno.dadosPessoa', 'matricula.turma', 'matricula.anoLectivo', 'itens', 'pagamentos.pagamento']);

        return $this->linha($cobranca) + [
            'matricula_estado' => $cobranca->matricula?->estado?->label(),
            'turma_nome' => $cobranca->matricula?->turma?->nome,
            'ano_lectivo_nome' => $cobranca->matricula?->anoLectivo?->nome,
            'observacao' => $cobranca->observacao,
            'motivo_cancelamento' => $cobranca->cancelamento_motivo,
            'itens' => $cobranca->itens->map(fn ($i) => [
                'id' => $i->id,
                'tipo' => $i->tipo,
                'nome' => $i->nome,
                'codigo' => $i->codigo,
                'variante_nome' => $i->variante_nome,
                'preco_unitario' => $i->preco_unitario->unidadesMenores(),
                'quantidade' => $i->quantidade,
                'total' => $i->total->unidadesMenores(),
            ])->values()->all(),
            'pagamentos' => $cobranca->pagamentos->map(fn ($l) => [
                'pagamento_id' => $l->pagamento_id,
                'numero' => $l->pagamento?->numero,
                'data' => $l->pagamento?->data_pagamento,
                'valor' => $l->valor->unidadesMenores(),
                'estado_descricao' => $l->pagamento?->estado_descricao,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function linha(Cobranca $c): array
    {
        return [
            'id' => $c->id,
            'matricula_id' => $c->matricula_id,
            'aluno_id' => $c->aluno_id,
            'aluno_nome' => $c->aluno?->dadosPessoa?->nome_completo,
            'aluno_numero' => $c->aluno?->numero_matricula,
            'matricula_numero' => $c->matricula?->numero_registo_matricula,
            'total' => $c->total->unidadesMenores(),
            'valor_pago' => $c->valor_pago->unidadesMenores(),
            'saldo' => $c->saldo(),
            'estado' => $c->estado->value,
            'estado_descricao' => $c->estado_descricao,
            'origem' => $c->origem->value,
            'origem_descricao' => $c->origem_descricao,
            'data_limite' => $c->data_limite?->toDateString(),
            'itens_count' => $c->itens_count ?? null,
            'criada_em' => $c->created_at?->toDateString(),
        ];
    }

    /**
     * Serve qualquer matrícula que o aluno chegou a ter (uma Concluída pode pedir uma declaração); as Canceladas ficam
     * de fora, salvo `$incluirCanceladas` (ex.: acordo sobre a dívida que uma matrícula cancelada deixou).
     *
     * @return list<array<string, mixed>>
     */
    public function pesquisarMatriculas(string $termo, bool $incluirCanceladas = false): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < 2) {
            return [];
        }

        return Matricula::query()
            ->with(['aluno.dadosPessoa', 'turma', 'anoLectivo'])
            ->where(fn ($g) => $g
                ->whereContem('numero_registo_matricula', $termo)
                ->orWhereHas('aluno', fn ($a) => $a->where(fn ($x) => $x
                    ->whereContem('numero_matricula', $termo)
                    ->orWhereHas('dadosPessoa', fn ($p) => $p->whereContem('nome_completo', $termo)))))
            ->when(! $incluirCanceladas, fn ($q) => $q->where('estado', '!=', EstadoMatriculaEnum::CANCELADA->value))
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [EstadoMatriculaEnum::ACTIVA->value])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Matricula $m) => $this->resumoDaMatricula($m))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function resumoDaMatricula(Matricula $m): array
    {
        $m->loadMissing(['aluno.dadosPessoa', 'turma', 'anoLectivo']);

        return [
            'id' => $m->id,
            'numero' => $m->numero_registo_matricula,
            'estado_descricao' => $m->estado?->label(),
            'aluno_id' => $m->aluno_id,
            'aluno_nome' => $m->aluno?->dadosPessoa?->nome_completo,
            'aluno_numero' => $m->aluno?->numero_matricula,
            'turma_nome' => $m->turma?->nome,
            'ano_lectivo_nome' => $m->anoLectivo?->nome,
            'ano_inicio' => $m->anoLectivo?->data_inicio?->toDateString(),
            'ano_fim' => $m->anoLectivo?->data_fim?->toDateString(),
        ];
    }

    /** @return array{produtos: list<array>, servicos: list<array>} itens activos para vender */
    public function catalogo(): array
    {
        return [
            'produtos' => Produto::query()->where('estado', Estado::ATIVO->value)->with('variantes')->orderBy('nome')->get()->map(fn (Produto $p) => [
                'id' => $p->id,
                'nome' => $p->nome,
                'codigo' => $p->codigo,
                'preco' => $p->preco->unidadesMenores(),
                'obrigatorio' => (bool) $p->obrigatorio,
                'controla_stock' => (bool) $p->controla_stock,
                'stock_quantidade' => (int) $p->stock_quantidade,
                'tem_variantes' => (bool) $p->tem_variantes,
                'variantes' => $p->variantes->map(fn ($v) => ['id' => $v->id, 'nome' => $v->nome, 'stock_quantidade' => (int) $v->stock_quantidade])->values()->all(),
            ])->values()->all(),
            'servicos' => Servico::query()->where('estado', Estado::ATIVO->value)->orderBy('nome')->get()->map(fn (Servico $s) => [
                'id' => $s->id,
                'nome' => $s->nome,
                'codigo' => $s->codigo,
                'preco' => $s->preco->unidadesMenores(),
                'obrigatorio' => (bool) $s->obrigatorio,
            ])->values()->all(),
        ];
    }

    /** @return list<array{value: int, label: string}> */
    public function estados(): array
    {
        return array_map(fn (EstadoCobranca $e) => ['value' => $e->value, 'label' => $e->label()], [
            EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA, EstadoCobranca::PAGA, EstadoCobranca::CANCELADA,
        ]);
    }
}
