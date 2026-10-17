<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\AnulacaoDePagamentos;
use Modules\Financeiro\Support\RotuloDePeriodo;
use Modules\Financeiro\Support\TaxaCambio;
use Modules\Matricula\Models\Matricula;

/**
 * Leitura de pagamentos e créditos (spec Pagamentos §7). Quantias em unidades menores; datas Y-m-d; instantes no fuso
 * da escola. Nunca escreve.
 */
class PagamentoConsultaService
{
    public const POR_PAGINA = 15;

    public function __construct(
        private RelogioDoTenant $relogio,
        private DistribuicaoDePagamentoService $distribuicao,
        private AnulacaoDePagamentos $anulacao,
    ) {
    }

    public function listar(array $filtros = []): LengthAwarePaginator
    {
        $estado = is_numeric($filtros['estado'] ?? null) ? EstadoPagamento::tryFrom((int) $filtros['estado']) : null;
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';
        $referencia = is_string($filtros['referencia'] ?? null) ? trim($filtros['referencia']) : '';

        return Pagamento::query()
            ->with(['matricula.aluno.dadosPessoa', 'metodo', 'credito'])
            ->withSum('distribuicoes as total_distribuido', 'valor')
            ->when($estado !== null, fn ($q) => $q->where('estado', $estado->value))
            ->when(is_numeric($filtros['metodo_pagamento_id'] ?? null), fn ($q) => $q->where('metodo_pagamento_id', (int) $filtros['metodo_pagamento_id']))
            ->when(is_numeric($filtros['aluno_id'] ?? null), fn ($q) => $q->where('aluno_id', (int) $filtros['aluno_id']))
            ->when(is_numeric($filtros['matricula_id'] ?? null), fn ($q) => $q->where('matricula_id', (int) $filtros['matricula_id']))
            ->when($this->data($filtros['data_de'] ?? null), fn ($q, string $dia) => $q->where('data_pagamento', '>=', $dia))
            ->when($this->data($filtros['data_ate'] ?? null), fn ($q, string $dia) => $q->where('data_pagamento', '<=', $dia))
            ->when($referencia !== '', fn ($q) => $q->whereContem('referencia', $referencia))
            ->when($pesquisa !== '', fn ($q) => $q->where(fn ($grupo) => $grupo
                ->whereContem('numero', $pesquisa)
                ->orWhereHas('matricula', fn ($matricula) => $matricula->withTrashed()->where(fn ($m) => $m
                    ->whereContem('numero_registo_matricula', $pesquisa)
                    ->orWhereHas('aluno', fn ($aluno) => $aluno->where(fn ($a) => $a
                        ->whereContem('numero_matricula', $pesquisa)
                        ->orWhereHas('dadosPessoa', fn ($pessoa) => $pessoa->whereContem('nome_completo', $pesquisa))))))))
            ->orderByDesc('data_pagamento')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (Pagamento $pagamento) => [
                'id' => $pagamento->id,
                'numero' => $pagamento->numero,
                'data_pagamento' => $pagamento->data_pagamento->toDateString(),
                'aluno_id' => $pagamento->aluno_id,
                'aluno_nome' => $pagamento->matricula?->aluno?->dadosPessoa?->nome_completo,
                'aluno_numero' => $pagamento->matricula?->aluno?->numero_matricula,
                'matricula_id' => $pagamento->matricula_id,
                'matricula_numero' => $pagamento->matricula?->numero_registo_matricula,
                'metodo_nome' => $pagamento->metodo?->nome,
                'referencia' => $pagamento->referencia,
                'valor' => $pagamento->valor->unidadesMenores(),
                'total_distribuido' => (int) $pagamento->total_distribuido,
                'credito_gerado' => $pagamento->credito?->valor->unidadesMenores() ?? 0,
                'estado' => $pagamento->estado->value,
                'estado_descricao' => $pagamento->estado->label(),
            ]);
    }

    /** @return Collection<int, MetodoPagamento> */
    public function metodos(): Collection
    {
        return MetodoPagamento::query()->orderBy('nome')->get(['id', 'nome', 'estado']);
    }

    /** @return Collection<int, MetodoPagamento> */
    public function metodosActivos(): Collection
    {
        return MetodoPagamento::query()->activos()->orderBy('nome')->get(['id', 'nome', 'tipo']);
    }

    /** @return list<array{value: int, label: string}> */
    public function estados(): array
    {
        return EstadoPagamento::opcoes();
    }

    /** @return array<string, mixed> */
    public function detalhe(Pagamento $pagamento): array
    {
        // load (não loadMissing): relações já carregadas (ex.: o `credito` devolvido pela Action) podem estar obsoletas.
        $pagamento->load([
            'matricula.aluno.dadosPessoa', 'matricula.turma', 'matricula.anoLectivo', 'metodo', 'criadoPor', 'anuladoPor',
            'distribuicoes.propina.plano', 'multas.multa.propina', 'credito.utilizacoes.propina.matricula', 'utilizacoesCombinadas.propina.matricula',
        ]);
        $fuso = $this->relogio->fuso();
        $credito = $pagamento->credito;

        return [
            'id' => $pagamento->id,
            'numero' => $pagamento->numero,
            'estado' => $pagamento->estado->value,
            'estado_descricao' => $pagamento->estado->label(),
            'data_pagamento' => $pagamento->data_pagamento->toDateString(),
            'valor' => $pagamento->valor->unidadesMenores(),
            'moeda' => $pagamento->moeda,
            'cambio_usd' => $pagamento->cambio_usd === null ? null : TaxaCambio::deMicros($pagamento->cambio_usd)->formatar(),
            'referencia' => $pagamento->referencia,
            'observacao' => $pagamento->observacao,
            'metodo' => ['id' => $pagamento->metodo?->id, 'nome' => $pagamento->metodo?->nome, 'tipo_descricao' => $pagamento->metodo?->tipo?->label()],
            'matricula' => $this->resumo($pagamento->matricula),
            'registado' => ['por' => $pagamento->criadoPor?->name, 'em' => $this->instante($pagamento->created_at, $fuso)],
            'anulacao' => $pagamento->estado === EstadoPagamento::ANULADO ? [
                'motivo' => $pagamento->motivo_anulacao,
                'por' => $pagamento->anuladoPor?->name,
                'em' => $this->instante($pagamento->anulado_em, $fuso),
            ] : null,
            'distribuicoes' => $pagamento->distribuicoes->map(fn (PagamentoPropina $d) => [
                'propina_id' => $d->propina_id,
                'periodo' => RotuloDePeriodo::periodo($d->propina->periodo_inicio->toDateString(), $d->propina->periodo_fim->toDateString()),
                'plano_nome' => $d->propina->plano?->nome,
                'valor' => $d->valor->unidadesMenores(),
                'estado_propina_descricao' => $d->propina->estadoResolvido($this->relogio->hoje())->label(),
            ])->values()->all(),
            'multas' => $pagamento->multas->map(fn (PagamentoMulta $d) => [
                'propina_id' => $d->multa->propina_id,
                'periodo' => RotuloDePeriodo::periodo($d->multa->propina->periodo_inicio->toDateString(), $d->multa->propina->periodo_fim->toDateString()),
                'valor' => $d->valor->unidadesMenores(),
                'estado_propina_descricao' => $d->multa->propina->estadoResolvido($this->relogio->hoje())->label(),
            ])->values()->all(),
            'credito' => $credito === null ? null : [
                'id' => $credito->id,
                'valor' => $credito->valor->unidadesMenores(),
                'valor_utilizado' => $credito->valor_utilizado->unidadesMenores(),
                'saldo' => $credito->saldo()->unidadesMenores(),
                'estado' => $credito->estado->value,
                'estado_descricao' => $credito->estado->label(),
                'utilizacoes' => $credito->utilizacoes->map(fn (CreditoUtilizacao $u) => $this->utilizacao($u))->values()->all(),
            ],
            'utilizacoes_combinadas' => $pagamento->utilizacoesCombinadas->map(fn (CreditoUtilizacao $u) => $this->utilizacao($u))->values()->all(),
            'anulacao_bloqueada' => $this->anulacao->motivoParaNaoAnular($pagamento),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function pesquisarMatriculas(string $termo): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < 2) {
            return [];
        }

        return Matricula::query()
            ->with(['aluno.dadosPessoa', 'turma', 'anoLectivo'])
            ->where(fn ($grupo) => $grupo
                ->whereContem('numero_registo_matricula', $termo)
                ->orWhereHas('aluno', fn ($aluno) => $aluno->where(fn ($a) => $a
                    ->whereContem('numero_matricula', $termo)
                    ->orWhereHas('dadosPessoa', fn ($pessoa) => $pessoa->whereContem('nome_completo', $termo)))))
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Matricula $m) => $this->resumo($m))
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function resumoDaMatricula(int $id): ?array
    {
        $matricula = Matricula::query()->with(['aluno.dadosPessoa', 'turma', 'anoLectivo'])->find($id);

        return $matricula === null ? null : $this->resumo($matricula);
    }

    /** @return array<string, mixed> */
    public function situacao(Matricula $matricula): array
    {
        $hoje = $this->relogio->hoje();
        $regras = RegraCobranca::doTenant();
        $propinas = $this->distribuicao->propinasPagaveis($matricula)
            ->sort(fn (Propina $a, Propina $b) => [$a->periodo_inicio->toDateString(), $a->ordem, $a->id] <=> [$b->periodo_inicio->toDateString(), $b->ordem, $b->id])
            ->values();
        $creditos = $this->distribuicao->creditosDisponiveis($matricula)->load('origem');

        return [
            'matricula' => $this->resumo($matricula->loadMissing(['aluno.dadosPessoa', 'turma', 'anoLectivo'])),
            'hoje' => $hoje->toDateString(),
            'regras' => [
                'permite_pagamento_parcial' => (bool) $regras->permite_pagamento_parcial,
                'permite_pagamento_antecipado' => (bool) $regras->permite_pagamento_antecipado,
            ],
            'propinas' => $propinas->map(function (Propina $p) use ($hoje) {
                $estado = $p->estadoResolvido($hoje);

                return [
                    'id' => $p->id,
                    'periodo' => RotuloDePeriodo::periodo($p->periodo_inicio->toDateString(), $p->periodo_fim->toDateString()),
                    'periodo_inicio' => $p->periodo_inicio->toDateString(),
                    'data_vencimento' => $p->data_vencimento->toDateString(),
                    'valor' => $p->valor->unidadesMenores(),
                    'valor_pago' => $p->valor_pago->unidadesMenores(),
                    'saldo' => $p->saldo()->unidadesMenores(),
                    'capital_em_divida' => $p->saldo()->unidadesMenores(),
                    'multa_pendente' => $p->multaPendente(),
                    'total_em_divida' => $p->saldo()->unidadesMenores() + $p->multaPendente(),
                    'estado' => $estado->value,
                    'estado_descricao' => $estado->label(),
                    'futura' => $p->periodo_inicio->toDateString() > $hoje->toDateString(),
                ];
            })->all(),
            'creditos' => $creditos->map(fn (Credito $c) => [
                'id' => $c->id,
                'valor' => $c->valor->unidadesMenores(),
                'saldo' => $c->saldo()->unidadesMenores(),
                'origem_numero' => $c->origem?->numero,
                'origem_data' => $c->origem?->data_pagamento?->toDateString(),
            ])->values()->all(),
            'credito_disponivel' => (int) $creditos->sum(fn (Credito $c) => $c->saldo()->unidadesMenores()),
        ];
    }

    /** @return array<string, mixed> */
    public function creditosDoAluno(Aluno $aluno): array
    {
        $aluno->loadMissing('dadosPessoa');
        $creditos = Credito::query()->where('aluno_id', $aluno->id)->with(['origem', 'utilizacoes.propina.matricula'])->orderBy('id')->get();

        return [
            'aluno' => ['id' => $aluno->id, 'nome' => $aluno->dadosPessoa?->nome_completo, 'numero' => $aluno->numero_matricula],
            'saldo_disponivel' => (int) $creditos->sum(fn (Credito $c) => $c->saldo()->unidadesMenores()),
            'creditos' => $creditos->map(fn (Credito $c) => [
                'id' => $c->id,
                'origem_pagamento_id' => $c->origem_pagamento_id,
                'origem_numero' => $c->origem?->numero,
                'origem_data' => $c->origem?->data_pagamento?->toDateString(),
                'valor' => $c->valor->unidadesMenores(),
                'valor_utilizado' => $c->valor_utilizado->unidadesMenores(),
                'saldo' => $c->saldo()->unidadesMenores(),
                'estado' => $c->estado->value,
                'estado_descricao' => $c->estado->label(),
                'utilizacoes' => $c->utilizacoes->map(fn (CreditoUtilizacao $u) => $this->utilizacao($u))->values()->all(),
            ])->values()->all(),
            'matriculas' => Matricula::query()->where('aluno_id', $aluno->id)->with(['turma', 'anoLectivo'])->orderByDesc('id')->get()
                ->map(fn (Matricula $m) => [
                    'id' => $m->id,
                    'numero' => $m->numero_registo_matricula,
                    'ano_lectivo_nome' => $m->anoLectivo?->nome,
                    'turma_nome' => $m->turma?->nome,
                    'estado_descricao' => $m->estado?->label(),
                ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function daPropina(Propina $propina): array
    {
        $linhas = PagamentoPropina::query()->where('propina_id', $propina->getKey())->with('pagamento')->get()
            ->map(fn (PagamentoPropina $d) => [
                'tipo' => 'pagamento',
                'id' => $d->id,
                'pagamento_id' => $d->pagamento_id,
                'numero' => $d->pagamento->numero,
                'data' => $d->pagamento->data_pagamento->toDateString(),
                'valor' => $d->valor->unidadesMenores(),
                'estado_descricao' => $d->pagamento->estado->label(),
                'activo' => $d->pagamento->estado === EstadoPagamento::CONFIRMADO,
            ])
            ->concat(CreditoUtilizacao::query()->where('propina_id', $propina->getKey())->with('credito.origem')->get()
                ->map(fn (CreditoUtilizacao $u) => [
                    'tipo' => 'credito',
                    'id' => $u->id,
                    'pagamento_id' => $u->credito?->origem_pagamento_id,
                    'numero' => $u->credito?->origem?->numero,
                    'data' => $u->data_utilizacao->toDateString(),
                    'valor' => $u->valor->unidadesMenores(),
                    'estado_descricao' => $u->estado->label(),
                    'activo' => $u->estado === EstadoUtilizacaoCredito::ACTIVA,
                ]));

        // A multa da propina (1:1) também conta para o histórico: pagamentos e créditos sobre ela.
        $multa = PropinaMulta::query()->where('propina_id', $propina->getKey())->first();

        if ($multa !== null) {
            $linhas = $linhas
                ->concat(PagamentoMulta::query()->where('propina_multa_id', $multa->id)->with('pagamento')->get()
                    ->map(fn (PagamentoMulta $d) => [
                        'tipo' => 'pagamento',
                        'id' => $d->id,
                        'pagamento_id' => $d->pagamento_id,
                        'numero' => $d->pagamento->numero,
                        'data' => $d->pagamento->data_pagamento->toDateString(),
                        'valor' => $d->valor->unidadesMenores(),
                        'estado_descricao' => $d->pagamento->estado->label(),
                        'activo' => $d->pagamento->estado === EstadoPagamento::CONFIRMADO,
                    ]))
                ->concat(CreditoUtilizacao::query()->where('propina_multa_id', $multa->id)->with('credito.origem')->get()
                    ->map(fn (CreditoUtilizacao $u) => [
                        'tipo' => 'credito',
                        'id' => $u->id,
                        'pagamento_id' => $u->credito?->origem_pagamento_id,
                        'numero' => $u->credito?->origem?->numero,
                        'data' => $u->data_utilizacao->toDateString(),
                        'valor' => $u->valor->unidadesMenores(),
                        'estado_descricao' => $u->estado->label(),
                        'activo' => $u->estado === EstadoUtilizacaoCredito::ACTIVA,
                    ]));
        }

        return $linhas->sortBy([['data', 'asc'], ['tipo', 'desc'], ['id', 'asc']])->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function resumo(?Matricula $m): ?array
    {
        return $m === null ? null : [
            'id' => $m->id,
            'numero' => $m->numero_registo_matricula,
            'data_matricula' => $m->data_matricula?->toDateString(),
            'estado_descricao' => $m->estado?->label(),
            'aluno_id' => $m->aluno_id,
            'aluno_nome' => $m->aluno?->dadosPessoa?->nome_completo,
            'aluno_numero' => $m->aluno?->numero_matricula,
            'turma_nome' => $m->turma?->nome,
            'ano_lectivo_nome' => $m->anoLectivo?->nome,
        ];
    }

    /** @return array<string, mixed> */
    private function utilizacao(CreditoUtilizacao $u): array
    {
        return [
            'id' => $u->id,
            'credito_id' => $u->credito_id,
            'pagamento_id' => $u->pagamento_id,
            'propina_id' => $u->propina_id,
            'periodo' => $u->propina === null ? null : RotuloDePeriodo::periodo($u->propina->periodo_inicio->toDateString(), $u->propina->periodo_fim->toDateString()),
            'matricula_numero' => $u->propina?->matricula?->numero_registo_matricula,
            'valor' => $u->valor->unidadesMenores(),
            'data_utilizacao' => $u->data_utilizacao->toDateString(),
            'estado' => $u->estado->value,
            'estado_descricao' => $u->estado->label(),
            'motivo_anulacao' => $u->motivo_anulacao,
        ];
    }

    private function instante(?CarbonInterface $instante, string $fuso): ?string
    {
        return $instante === null ? null : $instante->copy()->setTimezone($fuso)->format('Y-m-d H:i');
    }

    private function data(mixed $valor): ?string
    {
        if (! is_string($valor) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $valor, $partes) !== 1) {
            return null;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? $valor : null;
    }
}
