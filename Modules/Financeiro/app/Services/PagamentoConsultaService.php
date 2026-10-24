<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Financeiro\Services\CancelarPropinasFuturasDaMatricula;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Models\Acordo;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\AnulacaoDePagamentos;
use Modules\Financeiro\Support\CacheDeTotais;
use Modules\Financeiro\Support\Multa;
use Modules\Financeiro\Support\ProgressoDoAcordo;
use Modules\Financeiro\Support\RegrasDoPagamento;
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
        private MoedaDoTenant $moeda,
        private CacheDeTotais $totais,
    ) {
    }

    /** Pagamentos que cumprem os filtros (sem estado quando `$comEstado` é falso: os indicadores mostram todos). */
    private function base(array $filtros, bool $comEstado): Builder
    {
        $estado = is_numeric($filtros['estado'] ?? null) ? EstadoPagamento::tryFrom((int) $filtros['estado']) : null;
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';
        $referencia = is_string($filtros['referencia'] ?? null) ? trim($filtros['referencia']) : '';

        return Pagamento::query()
            ->when($comEstado && $estado !== null, fn ($q) => $q->where('estado', $estado->value))
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
                        ->orWhereHas('dadosPessoa', fn ($pessoa) => $pessoa->whereContem('nome_completo', $pesquisa))))))));
    }

    /**
     * Números para os cartões do topo (pagamentos e recibos), para os filtros escolhidos: recebido, anulados, crédito
     * gerado e o último recibo. Unidades menores.
     *
     * @return array<string, mixed>
     */
    public function indicadores(array $filtros = []): array
    {
        return $this->totais->lembrar('pagamentos', $filtros, function () use ($filtros) {
            $agregado = fn (EstadoPagamento $e) => $this->base($filtros, false)->where('estado', $e->value)
                ->selectRaw('COUNT(*) as n, COALESCE(SUM(valor), 0) as soma')->first();
            $confirmados = $agregado(EstadoPagamento::CONFIRMADO);
            $anulados = $agregado(EstadoPagamento::ANULADO);
            $ultimo = $this->base($filtros, false)->where('estado', EstadoPagamento::CONFIRMADO->value)->orderByDesc('data_pagamento')->orderByDesc('id')->first(['numero', 'data_pagamento']);

            return [
                'recebido' => (int) $confirmados->soma,
                'pagamentos' => (int) $confirmados->n,
                'media' => $confirmados->n > 0 ? intdiv((int) $confirmados->soma, (int) $confirmados->n) : 0,
                'anulados' => (int) $anulados->n,
                'anulados_valor' => (int) $anulados->soma,
                'credito_gerado' => (int) Credito::query()->where('estado', '!=', EstadoCredito::ANULADO->value)
                    ->whereIn('origem_pagamento_id', $this->base($filtros, false)->where('estado', EstadoPagamento::CONFIRMADO->value)->select('id'))->sum('valor'),
                'ultimo_numero' => $ultimo?->numero,
                'ultima_data' => $ultimo?->data_pagamento?->toDateString(),
            ];
        });
    }

    /**
     * Últimos pagamentos confirmados, para o Dashboard. Valor em unidades menores.
     *
     * @return list<array{numero: string, data: string|null, valor: int, aluno: string|null}>
     */
    public function ultimos(int $quantidade = 5): array
    {
        return $this->base([], false)->where('estado', EstadoPagamento::CONFIRMADO->value)
            ->with('matricula.aluno.dadosPessoa')
            ->orderByDesc('data_pagamento')->orderByDesc('id')->limit($quantidade)->get()
            ->map(fn (Pagamento $p) => [
                'numero' => $p->numero,
                'data' => $p->data_pagamento?->toDateString(),
                'valor' => $p->valor?->unidadesMenores() ?? 0,
                'aluno' => $p->matricula?->aluno?->dadosPessoa?->nome_completo,
            ])->all();
    }

    public function listar(array $filtros = [], int $porPagina = self::POR_PAGINA): LengthAwarePaginator
    {
        return $this->base($filtros, true)
            ->with(['matricula.aluno.dadosPessoa', 'metodo', 'credito'])
            ->withSum('distribuicoes as total_distribuido', 'valor')
            ->orderByDesc('data_pagamento')
            ->orderByDesc('id')
            ->paginate($porPagina)
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

    /** @return Collection<int, array<string, mixed>> os métodos activos com o tipo e os dados de cada um */
    public function metodosActivos(): Collection
    {
        return MetodoPagamento::query()->activos()->orderBy('nome')
            ->get(['id', 'nome', 'tipo', 'titular', 'banco', 'iban', 'numero_conta', 'telefone', 'exige_comprovativo'])
            ->map(fn (MetodoPagamento $metodo) => [
                'id' => $metodo->id,
                'nome' => $metodo->nome,
                'tipo' => $metodo->tipo->value,
                'tipo_descricao' => $metodo->tipo->label(),
                'titular' => $metodo->titular,
                'banco' => $metodo->banco,
                'iban' => $metodo->iban,
                'numero_conta' => $metodo->numero_conta,
                'telefone' => $metodo->telefone,
                'exige_comprovativo' => $metodo->exige_comprovativo,
            ]);
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
            'distribuicoes.propina.plano', 'multas.multa.propina',
            'credito.utilizacoes.propina.matricula', 'credito.utilizacoes.multa.propina.matricula',
            'utilizacoesCombinadas.propina.matricula', 'utilizacoesCombinadas.multa.propina.matricula',
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
            'metodo' => [
                'id' => $pagamento->metodo?->id,
                'nome' => $pagamento->metodo?->nome,
                'tipo' => $pagamento->metodo?->tipo?->value,
                'tipo_descricao' => $pagamento->metodo?->tipo?->label(),
                'titular' => $pagamento->metodo?->titular,
                'banco' => $pagamento->metodo?->banco,
                'iban' => $pagamento->metodo?->iban,
                'numero_conta' => $pagamento->metodo?->numero_conta,
                'telefone' => $pagamento->metodo?->telefone,
            ],
            'matricula' => $this->resumo($pagamento->matricula),
            'registado' => ['por' => $pagamento->criadoPor?->name, 'em' => $this->instante($pagamento->created_at, $fuso)],
            'comprovativo' => $pagamento->comprovativo_path === null ? null : [
                'nome_original' => $pagamento->comprovativo_nome_original,
            ],
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
                // Ainda há algo por pagar nesta propina (capital ou multa)? Então a interface oferece «Pagar».
                'em_divida' => in_array($d->propina->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true) || $d->propina->multaPendente() > 0,
            ])->values()->all(),
            'multas' => $pagamento->multas->map(fn (PagamentoMulta $d) => [
                'propina_id' => $d->multa->propina_id,
                'periodo' => RotuloDePeriodo::periodo($d->multa->propina->periodo_inicio->toDateString(), $d->multa->propina->periodo_fim->toDateString()),
                'valor' => $d->valor->unidadesMenores(),
                'estado_propina_descricao' => $d->multa->propina->estadoResolvido($this->relogio->hoje())->label(),
                'regra' => $d->multa->tipo === null ? null : Multa::descreverRegra($d->multa->tipo, (int) $d->multa->valor_regra, $d->multa->escalao_ordem, $this->moeda->atual()),
                'em_divida' => in_array($d->multa->propina->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true) || $d->multa->propina->multaPendente() > 0,
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

    /**
     * Agregação para o recibo: por propina do pagamento, o que foi pago em dinheiro e o que foi pago com crédito,
     * separando capital e multa (spec Pagamentos §9). Uma utilização de crédito sobre uma multa (propina_multa_id)
     * é atribuída ao período da propina dessa multa. Nunca escreve.
     *
     * @return array{periodos: list<array<string, mixed>>, recebido_em_dinheiro: int, pago_com_credito: int, total_liquidado: int}
     */
    public function recibo(Pagamento $pagamento): array
    {
        $pagamento->load([
            'distribuicoes.propina.plano',
            'multas.multa.propina.plano',
            'utilizacoesCombinadas.propina.plano',
            'utilizacoesCombinadas.multa.propina.plano',
            'cobrancas.cobranca.itens',
        ]);

        $periodos = [];

        // Produtos e serviços pagos por este pagamento (uma linha por item, ao preço da venda).
        $itens = [];

        foreach ($pagamento->cobrancas as $linha) {
            foreach ($linha->cobranca->itens as $item) {
                $itens[] = [
                    'descricao' => $item->nome . ($item->variante_nome ? " ({$item->variante_nome})" : '') . ($item->quantidade > 1 ? " × {$item->quantidade}" : ''),
                    'valor' => $item->total->unidadesMenores(),
                ];
            }
        }
        $totalItens = array_sum(array_column($itens, 'valor'));

        $base = function (Propina $propina): array {
            return [
                'propina_id' => (int) $propina->getKey(),
                'periodo_inicio' => $propina->periodo_inicio->toDateString(),
                'ordem' => $propina->ordem,
                'periodo' => RotuloDePeriodo::periodo($propina->periodo_inicio->toDateString(), $propina->periodo_fim->toDateString()),
                'plano' => $propina->plano?->nome,
                'capital_dinheiro' => 0,
                'capital_credito' => 0,
                'multa_dinheiro' => 0,
                'multa_credito' => 0,
            ];
        };

        foreach ($pagamento->distribuicoes as $distribuicao) {
            $id = (int) $distribuicao->propina_id;
            $periodos[$id] ??= $base($distribuicao->propina);
            $periodos[$id]['capital_dinheiro'] += $distribuicao->valor->unidadesMenores();
        }

        foreach ($pagamento->multas as $distribuicao) {
            $id = (int) $distribuicao->multa->propina_id;
            $periodos[$id] ??= $base($distribuicao->multa->propina);
            $periodos[$id]['multa_dinheiro'] += $distribuicao->valor->unidadesMenores();
        }

        foreach ($pagamento->utilizacoesCombinadas as $utilizacao) {
            if ($utilizacao->propina_id !== null) {
                $id = (int) $utilizacao->propina_id;
                $periodos[$id] ??= $base($utilizacao->propina);
                $periodos[$id]['capital_credito'] += $utilizacao->valor->unidadesMenores();
            } elseif ($utilizacao->propina_multa_id !== null) {
                $id = (int) $utilizacao->multa->propina_id;
                $periodos[$id] ??= $base($utilizacao->multa->propina);
                $periodos[$id]['multa_credito'] += $utilizacao->valor->unidadesMenores();
            }
        }

        usort($periodos, fn (array $a, array $b) => [$a['periodo_inicio'], $a['ordem'], $a['propina_id']] <=> [$b['periodo_inicio'], $b['ordem'], $b['propina_id']]);

        $recebido = 0;
        $credito = 0;

        $periodos = array_map(function (array $periodo) use (&$recebido, &$credito): array {
            $recebido += $periodo['capital_dinheiro'] + $periodo['multa_dinheiro'];
            $credito += $periodo['capital_credito'] + $periodo['multa_credito'];

            return [
                'propina_id' => $periodo['propina_id'],
                'periodo' => $periodo['periodo'],
                'plano' => $periodo['plano'],
                'capital_dinheiro' => $periodo['capital_dinheiro'],
                'capital_credito' => $periodo['capital_credito'],
                'multa_dinheiro' => $periodo['multa_dinheiro'],
                'multa_credito' => $periodo['multa_credito'],
                'total' => $periodo['capital_dinheiro'] + $periodo['capital_credito'] + $periodo['multa_dinheiro'] + $periodo['multa_credito'],
            ];
        }, $periodos);

        return [
            'periodos' => array_values($periodos),
            'itens' => $itens,
            'recebido_em_dinheiro' => $recebido + $totalItens,
            'pago_com_credito' => $credito,
            'total_liquidado' => $recebido + $totalItens + $credito,
        ];
    }

    /**
     * Dados de contacto e identificação do aluno para o recibo (só aí: a pesquisa de matrículas não os expõe).
     *
     * @return array{identificacao: ?string, email: ?string, telefone: ?string}
     */
    public function alunoDoRecibo(Pagamento $pagamento): array
    {
        $pagamento->loadMissing('matricula.aluno.dadosPessoa');
        $pessoa = $pagamento->matricula?->aluno?->dadosPessoa;

        return [
            'identificacao' => $pessoa?->numero_identificacao,
            'email' => $pessoa?->email,
            'telefone' => $pessoa?->telefone,
        ];
    }

    /**
     * Os dados da escola para o cabeçalho do recibo (1 tenant = 1 escola = 1 estabelecimento).
     *
     * @return array{nome: ?string, nif: ?string, morada: ?string, telefone: ?string, email: ?string, logotipo_url: ?string}
     */
    public function escola(): array
    {
        $escola = Estabelecimento::query()->first();

        return [
            'nome' => $escola?->nome,
            'nif' => $escola?->nif,
            'morada' => collect([$escola?->endereco, $escola?->municipio, $escola?->provincia])->filter()->implode(', ') ?: null,
            'telefone' => $escola?->telefone,
            'email' => $escola?->email,
            'logotipo_url' => $escola?->logotipo_url,
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
            // Matrícula terminada (cancelada, concluída, transferida) só aparece se ainda deixou dívida por pagar.
            ->where(fn ($estado) => $estado
                ->whereNotIn('estado', array_map(fn (EstadoMatriculaEnum $e) => $e->value, CancelarPropinasFuturasDaMatricula::ESTADOS_TERMINAIS))
                ->orWhereIn('id', Propina::query()->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])->select('matricula_id'))
                ->orWhereIn('id', Propina::query()->whereIn('id', PropinaMulta::query()
                    ->whereIn('estado', [EstadoMulta::ACTIVA->value, EstadoMulta::REDUZIDA->value])
                    ->whereColumn('valor_pago', '<', 'valor')->select('propina_id'))->select('matricula_id')))
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
        $regras = RegrasDoPagamento::paraMatricula((int) $matricula->id);
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
            'cobrancas' => $this->cobrancasDaMatricula($matricula, $hoje->toDateString()),
            'acordo' => $this->acordoActivo($matricula),
        ];
    }

    /**
     * O acordo de pagamento activo da matrícula, para o ecrã de pagamento explicar porque o pagamento parcial é permitido.
     *
     * @return array{id: int, em_falta: int, proxima: ?array{ordem: int, data_vencimento: string, em_falta: int}}|null
     */
    private function acordoActivo(Matricula $matricula): ?array
    {
        $acordo = Acordo::query()->where('matricula_id', $matricula->id)->where('estado', EstadoAcordo::ACTIVO->value)->first();

        if ($acordo === null) {
            return null;
        }

        $p = app(ProgressoDoAcordo::class)->de($acordo);

        return ['id' => $acordo->id, 'em_falta' => $p['em_falta'], 'proxima' => $p['proxima']];
    }

    /**
     * Porque é que pode não haver nada a pagar: quantas propinas existem (activas), quantas estão pagas e qual é a
     * próxima ainda por pagar (para a mensagem do ecrã de pagamento ser factual).
     *
     * @return array{geradas: int, pagas: int, proxima: ?array{periodo: string, inicio: string}}
     */
    private function cobrancasDaMatricula(Matricula $matricula, string $hoje): array
    {
        $activas = Propina::query()->where('matricula_id', $matricula->id)->activas();
        $proxima = (clone $activas)->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->where('periodo_inicio', '>', $hoje)->orderBy('periodo_inicio')->first();

        return [
            'geradas' => (clone $activas)->count(),
            'pagas' => (clone $activas)->where('estado', EstadoCobranca::PAGA->value)->count(),
            'proxima' => $proxima === null ? null : [
                'periodo' => RotuloDePeriodo::periodo($proxima->periodo_inicio->toDateString(), $proxima->periodo_fim->toDateString()),
                'inicio' => $proxima->periodo_inicio->toDateString(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function creditosDoAluno(Aluno $aluno): array
    {
        $aluno->loadMissing('dadosPessoa');
        $creditos = Credito::query()->where('aluno_id', $aluno->id)->with(['origem', 'utilizacoes.propina.matricula', 'utilizacoes.multa.propina.matricula'])->orderBy('id')->get();

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
        $ehMulta = $u->propina_multa_id !== null;
        $propina = $ehMulta ? $u->multa?->propina : $u->propina;
        $periodo = $propina === null
            ? null
            : RotuloDePeriodo::periodo($propina->periodo_inicio->toDateString(), $propina->periodo_fim->toDateString());

        return [
            'id' => $u->id,
            'credito_id' => $u->credito_id,
            'pagamento_id' => $u->pagamento_id,
            'propina_id' => $propina?->getKey(),
            'periodo' => $periodo === null ? null : ($ehMulta ? "Multa {$periodo}" : $periodo),
            'matricula_numero' => $propina?->matricula?->numero_registo_matricula,
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
