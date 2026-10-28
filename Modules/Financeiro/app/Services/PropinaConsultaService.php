<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Bolsa;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Support\AnulacaoDePropinas;
use Modules\Financeiro\Support\CancelamentoDePropinas;
use Modules\Financeiro\Support\CacheDeTotais;
use Modules\Financeiro\Support\Multa;
use Modules\Financeiro\Support\RotuloDePeriodo;

/**
 * Leitura das propinas para a lista (spec §10). O estado mostrado e filtrado é o resolvido para o "hoje"
 * da escola (§4, §8.2b): o filtro usa o scope SQL de F1, a coluna usa EstadoCobranca::resolver.
 */
class PropinaConsultaService
{
    public const POR_PAGINA = 15;

    public function __construct(
        private RelogioDoTenant $relogio,
        private CancelamentoDePropinas $cancelamento,
        private AnulacaoDePropinas $anulacao,
        private PagamentoConsultaService $pagamentos,
        private MoedaDoTenant $moeda,
        private CacheDeTotais $totais,
    ) {
    }

    /** Propinas que cumprem os filtros (sem estado quando `$comEstado` é falso: os indicadores mostram todos os estados). */
    private function base(array $filtros, bool $comEstado): Builder
    {
        $hoje = $this->relogio->hoje();
        $estado = is_numeric($filtros['estado'] ?? null) ? EstadoCobranca::tryFrom((int) $filtros['estado']) : null;
        $origem = is_numeric($filtros['origem_geracao'] ?? null) ? OrigemGeracaoPropina::tryFrom((int) $filtros['origem_geracao']) : null;
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';

        return Propina::query()
            ->when(is_numeric($filtros['ano_lectivo_id'] ?? null), fn ($query) => $query->where('ano_lectivo_id', (int) $filtros['ano_lectivo_id']))
            ->when($comEstado && $estado !== null, fn ($query) => $query->comEstadoResolvido($estado, $hoje))
            ->when(is_numeric($filtros['plano_propina_id'] ?? null), fn ($query) => $query->where('plano_propina_id', (int) $filtros['plano_propina_id']))
            ->when(is_numeric($filtros['matricula_id'] ?? null), fn ($query) => $query->where('matricula_id', (int) $filtros['matricula_id']))
            ->when($origem !== null, fn ($query) => $query->where('origem_geracao', $origem->value))
            ->when($this->data($filtros['vencimento_de'] ?? null), fn ($query, string $dia) => $query->where('data_vencimento', '>=', $dia))
            ->when($this->data($filtros['vencimento_ate'] ?? null), fn ($query, string $dia) => $query->where('data_vencimento', '<=', $dia))
            ->when($pesquisa !== '', fn ($query) => $query->whereHas('matricula', fn ($matricula) => $matricula->where(fn ($grupo) => $grupo
                ->whereContem('numero_registo_matricula', $pesquisa)
                ->orWhereHas('aluno', fn ($aluno) => $aluno->where(fn ($subgrupo) => $subgrupo
                    ->whereContem('numero_matricula', $pesquisa)
                    ->orWhereHas('dadosPessoa', fn ($pessoa) => $pessoa->whereContem('nome_completo', $pesquisa)))))));
    }

    /**
     * Números para os cartões do topo da lista: emitido, recebido, em atraso, a vencer e multas por cobrar, para os
     * filtros escolhidos (todos os estados). Valores em unidades menores.
     *
     * @return array<string, int>
     */
    public function indicadores(array $filtros = []): array
    {
        return $this->totais->lembrar('propinas', $filtros, fn () => $this->calcularIndicadores($filtros));
    }

    /**
     * Emitido e recebido por mês de vencimento (propinas activas), do mais antigo para o mais recente. Unidades menores.
     *
     * @return list<array{mes: string, emitido: int, recebido: int}>
     */
    public function porMes(array $filtros = []): array
    {
        return $this->totais->lembrar('propinas-por-mes', $filtros, function () use ($filtros) {
            $meses = [];

            $dias = $this->base($filtros, false)->whereIn('estado', Propina::ESTADOS_ACTIVOS)
                ->selectRaw('data_vencimento as dia, SUM(valor) as emitido_dia, SUM(valor_pago) as recebido_dia')
                ->groupBy('data_vencimento')->get();

            foreach ($dias as $linha) {
                $mes = substr((string) $linha->getRawOriginal('dia'), 0, 7);
                $meses[$mes] ??= ['mes' => $mes, 'emitido' => 0, 'recebido' => 0];
                $meses[$mes]['emitido'] += (int) $linha->emitido_dia;
                $meses[$mes]['recebido'] += (int) $linha->recebido_dia;
            }

            ksort($meses);

            return array_values($meses);
        });
    }

    /**
     * Previsão de caixa: o que vence nos próximos `$dias` dias, por semana, e quanto se prevê receber aplicando a taxa
     * histórica de cobrança (recebido/emitido das propinas vencidas nos últimos 90 dias; `null` sem histórico).
     * Unidades menores.
     *
     * @return array{semanas: list<array{inicio: string, a_vencer: int, previsto: int|null}>, a_vencer: int, previsto: int|null, taxa: int|null, em_atraso: int}
     */
    public function previsaoDeCaixa(int $dias = 30): array
    {
        return $this->totais->lembrar('previsao-de-caixa', ['dias' => $dias], function () use ($dias) {
            $hoje = $this->relogio->hoje();
            $fim = $hoje->addDays($dias);

            $historico = Propina::query()->whereIn('estado', Propina::ESTADOS_ACTIVOS)
                ->whereBetween('data_vencimento', [$hoje->subDays(90)->toDateString(), $hoje->subDay()->toDateString()])
                ->selectRaw('COALESCE(SUM(valor), 0) as emitido_h, COALESCE(SUM(valor_pago), 0) as pago_h')->first();
            $taxa = (int) $historico->emitido_h > 0 ? min(1.0, (int) $historico->pago_h / (int) $historico->emitido_h) : null;

            $porDia = Propina::query()->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
                ->whereBetween('data_vencimento', [$hoje->toDateString(), $fim->toDateString()])
                ->selectRaw('data_vencimento as dia, SUM(valor - valor_pago) as saldo_dia')
                ->groupBy('data_vencimento')->get();

            $semanas = [];
            for ($i = 0; $i * 7 <= $dias; $i++) {
                $semanas[$i] = ['inicio' => $hoje->addDays($i * 7)->toDateString(), 'a_vencer' => 0, 'previsto' => $taxa === null ? null : 0];
            }

            foreach ($porDia as $linha) {
                $indice = intdiv((int) $hoje->diffInDays(CarbonImmutable::parse(substr((string) $linha->getRawOriginal('dia'), 0, 10)), false), 7);
                $semanas[$indice]['a_vencer'] += (int) $linha->saldo_dia;
            }

            foreach ($semanas as &$semana) {
                $semana['previsto'] = $taxa === null ? null : (int) round($semana['a_vencer'] * $taxa);
            }
            unset($semana);

            $total = array_sum(array_column($semanas, 'a_vencer'));
            $atraso = (int) Propina::query()->comEstadoResolvido(EstadoCobranca::EM_ATRASO, $hoje)->selectRaw('COALESCE(SUM(valor - valor_pago), 0) as saldo_atraso')->first()->saldo_atraso;

            return [
                'semanas' => array_values($semanas),
                'a_vencer' => $total,
                'previsto' => $taxa === null ? null : (int) round($total * $taxa),
                'taxa' => $taxa === null ? null : (int) round($taxa * 100),
                'em_atraso' => $atraso,
            ];
        });
    }

    /**
     * Vencimentos de um mês (por omissão o corrente; `YYYY-MM`), dia a dia (propinas activas): quantas, quanto se emitiu, quanto já foi recebido
     * e quantas foram liquidadas depois do vencimento (e com quantos dias de atraso, no máximo). Unidades menores. Só dias com vencimentos.
     *
     * @return array{mes: string, dias: list<array{dia: string, quantidade: int, emitido: int, recebido: int, tardias: int, dias_de_atraso: int}>}
     */
    public function calendarioDoMes(?string $mes = null): array
    {
        $inicio = $mes === null ? $this->relogio->hoje()->startOfMonth() : CarbonImmutable::createFromFormat('!Y-m', $mes);

        return $this->totais->lembrar('calendario-do-mes', ['mes' => $inicio->format('Y-m')], function () use ($inicio) {
            $dias = Propina::query()->whereIn('estado', Propina::ESTADOS_ACTIVOS)
                ->whereBetween('data_vencimento', [$inicio->toDateString(), $inicio->endOfMonth()->toDateString()])
                ->selectRaw('data_vencimento as dia, COUNT(*) as n, SUM(valor) as emitido_dia, SUM(valor_pago) as recebido_dia, SUM(CASE WHEN capital_liquidado_em > data_vencimento THEN 1 ELSE 0 END) as tarde_n, MAX(CASE WHEN capital_liquidado_em > data_vencimento THEN capital_liquidado_em END) as ultima_tardia')
                ->groupBy('data_vencimento')->orderBy('data_vencimento')->get();

            return [
                'mes' => $inicio->format('Y-m'),
                'dias' => $dias->map(function ($linha) {
                    $dia = substr((string) $linha->getRawOriginal('dia'), 0, 10);
                    $tardia = $linha->getRawOriginal('ultima_tardia');

                    return [
                        'dia' => $dia,
                        'quantidade' => (int) $linha->n,
                        'emitido' => (int) $linha->emitido_dia,
                        'recebido' => (int) $linha->recebido_dia,
                        'tardias' => (int) $linha->tarde_n,
                        'dias_de_atraso' => $tardia === null ? 0 : (int) CarbonImmutable::parse($dia)->diffInDays(CarbonImmutable::parse(substr((string) $tardia, 0, 10))),
                    ];
                })->all(),
            ];
        });
    }

    /** @return array<string, int> */
    private function calcularIndicadores(array $filtros): array
    {
        $hoje = $this->relogio->hoje();
        $agregado = fn (Builder $q) => $q->selectRaw('COUNT(*) as n, COALESCE(SUM(valor), 0) as emitido, COALESCE(SUM(valor_pago), 0) as pago, COALESCE(SUM(valor - valor_pago), 0) as saldo')->first();
        $total = $agregado($this->base($filtros, false)->whereIn('estado', Propina::ESTADOS_ACTIVOS));
        $atraso = $agregado($this->base($filtros, false)->comEstadoResolvido(EstadoCobranca::EM_ATRASO, $hoje));
        $pendentes = $agregado($this->base($filtros, false)->comEstadoResolvido(EstadoCobranca::PENDENTE, $hoje));
        $emAberto = $agregado($this->base($filtros, false)->comEstadoResolvido(EstadoCobranca::EM_ABERTO, $hoje));
        $multas = PropinaMulta::query()
            ->whereIn('estado', [EstadoMulta::ACTIVA->value, EstadoMulta::REDUZIDA->value])
            ->whereIn('propina_id', $this->base($filtros, false)->select('id'))
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(valor - valor_pago), 0) as pendente')->first();

        return [
            'propinas' => (int) $total->n,
            'emitido' => (int) $total->emitido,
            'recebido' => (int) $total->pago,
            'em_atraso_n' => (int) $atraso->n,
            'em_atraso_valor' => (int) $atraso->saldo,
            'a_vencer_n' => (int) $pendentes->n + (int) $emAberto->n,
            'a_vencer_valor' => (int) $pendentes->saldo + (int) $emAberto->saldo,
            'multas_n' => (int) $multas->n,
            'multas_valor' => (int) $multas->pendente,
        ];
    }

    public function listar(array $filtros = []): LengthAwarePaginator
    {
        $hoje = $this->relogio->hoje();
        $fuso = $this->relogio->fuso();

        $pagina = $this->base($filtros, true)
            ->with(['matricula.aluno.dadosPessoa', 'matricula.turma', 'plano', 'multa'])
            ->orderBy('data_vencimento')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        $ultimos = $this->ultimosPagamentos($pagina->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $pagina->through(fn (Propina $propina) => $this->linha($propina, $hoje, $fuso) + ['ultimo_pagamento_id' => $ultimos[(int) $propina->id] ?? null]);
    }

    /**
     * O pagamento Confirmado mais recente que tocou cada propina (capital ou multa, em dinheiro ou com crédito no
     * mesmo acto): é o recibo que a lista oferece. Uma consulta por tipo para a página inteira, sem N+1.
     *
     * @param  list<int>  $propinaIds
     * @return array<int, int> propina_id => pagamento_id
     */
    private function ultimosPagamentos(array $propinaIds): array
    {
        if ($propinaIds === []) {
            return [];
        }

        $confirmado = fn ($consulta) => $consulta->where('estado', EstadoPagamento::CONFIRMADO->value);
        $ultimos = [];
        $registar = function (int $propinaId, int $pagamentoId) use (&$ultimos): void {
            $ultimos[$propinaId] = max($ultimos[$propinaId] ?? 0, $pagamentoId);
        };

        foreach (PagamentoPropina::query()->whereIn('propina_id', $propinaIds)->whereHas('pagamento', $confirmado)->get(['propina_id', 'pagamento_id']) as $linha) {
            $registar((int) $linha->propina_id, (int) $linha->pagamento_id);
        }

        foreach (PagamentoMulta::query()->with('multa:id,propina_id')->whereHas('multa', fn ($m) => $m->whereIn('propina_id', $propinaIds))->whereHas('pagamento', $confirmado)->get(['pagamento_id', 'propina_multa_id']) as $linha) {
            $registar((int) $linha->multa->propina_id, (int) $linha->pagamento_id);
        }

        foreach (CreditoUtilizacao::query()->whereIn('propina_id', $propinaIds)->whereNotNull('pagamento_id')->whereHas('pagamento', $confirmado)->get(['propina_id', 'pagamento_id']) as $linha) {
            $registar((int) $linha->propina_id, (int) $linha->pagamento_id);
        }

        return $ultimos;
    }

    /**
     * @return Collection<int, PlanoPropina>
     */
    public function planos(): Collection
    {
        return PlanoPropina::query()->orderBy('nome')->get(['id', 'nome', 'ano_lectivo_id', 'estado']);
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function estados(): array
    {
        return array_map(fn (EstadoCobranca $e) => ['value' => $e->value, 'label' => $e->label()], EstadoCobranca::cases());
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function origens(): array
    {
        return array_map(fn (OrigemGeracaoPropina $o) => ['value' => $o->value, 'label' => $o->label()], OrigemGeracaoPropina::cases());
    }

    /**
     * Detalhe de uma propina (spec §10): estado resolvido para o "hoje" da escola, snapshot, plano de origem,
     * matrícula, cancelamento/anulação e um histórico simples derivado das colunas (nenhuma tabela nova).
     * Pagamentos e utilizações de crédito, por data (PagamentoConsultaService::daPropina). Ajustes de preço (F4) do mais antigo para o mais recente.
     *
     * @return array<string, mixed>
     */
    public function detalhe(Propina $propina): array
    {
        $propina->loadMissing([
            'matricula.aluno.dadosPessoa', 'matricula.turma', 'matricula.anoLectivo', 'plano', 'multa', 'criadoPor', 'canceladoPor', 'anuladoPor',
        ]);

        $hoje = $this->relogio->hoje();
        $fuso = $this->relogio->fuso();
        $estado = $propina->estadoResolvido($hoje);
        $inicio = $propina->periodo_inicio->toDateString();
        $fim = $propina->periodo_fim->toDateString();
        $matricula = $propina->matricula;
        $plano = $propina->plano;

        return [
            'id' => $propina->id,
            'estado' => $estado->value,
            'estado_descricao' => $estado->label(),
            'estado_persistido' => $propina->estado->value,
            'estado_persistido_descricao' => $propina->estado->label(),
            'ordem' => $propina->ordem,
            'periodo' => RotuloDePeriodo::periodo($inicio, $fim),
            'periodo_inicio' => $inicio,
            'periodo_fim' => $fim,
            'valor_original' => $propina->valor_original->unidadesMenores(),
            // Bolsa de estudo que abateu ao valor do plano (nome e quanto abateu); null sem bolsa.
            'bolsa' => $propina->bolsa_id === null ? null : ['nome' => Bolsa::query()->whereKey($propina->bolsa_id)->value('nome'), 'valor_bolsa' => (int) $propina->valor_bolsa],
            'valor' => $propina->valor->unidadesMenores(),
            'valor_pago' => $propina->valor_pago->unidadesMenores(),
            'saldo' => $propina->saldo()->unidadesMenores(),
            'multa' => $this->resumoMulta($propina->multa),
            'total_em_divida' => $propina->saldo()->unidadesMenores() + $propina->multaPendente(),
            'data_vencimento' => $propina->data_vencimento->toDateString(),
            'dias_tolerancia' => $propina->dias_tolerancia,
            'data_limite' => $propina->data_limite->toDateString(),
            'moeda' => $propina->moeda,
            'cambio_usd' => $propina->taxaCambio()?->formatar(),
            'origem_geracao' => $propina->origem_geracao->value,
            'origem_geracao_descricao' => $propina->origem_geracao->label(),
            'motivo_geracao' => $propina->motivo_geracao,
            'geracao_tardia' => $this->geracaoTardia($propina, $fuso),
            'plano' => $plano === null ? null : [
                'id' => $plano->id,
                'nome' => $plano->nome,
                'data_inicio' => $plano->data_inicio?->toDateString(),
                'periodicidade' => $plano->periodicidade?->label(),
                'valor_actual' => $plano->valor->unidadesMenores(),
            ],
            'matricula' => $matricula === null ? null : [
                'id' => $matricula->id,
                'numero' => $matricula->numero_registo_matricula,
                'estado_descricao' => $matricula->estado?->label(),
                'data_matricula' => $matricula->data_matricula?->toDateString(),
                'data_fim' => $matricula->data_fim?->toDateString(),
                'aluno_nome' => $matricula->aluno?->dadosPessoa?->nome_completo,
                'aluno_numero' => $matricula->aluno?->numero_matricula,
                'turma_nome' => $matricula->turma?->nome,
                'ano_lectivo_nome' => $matricula->anoLectivo?->nome,
            ],
            'cancelamento' => $propina->estado === EstadoCobranca::CANCELADA ? [
                'motivo' => $propina->motivo_cancelamento,
                'por' => $propina->canceladoPor?->name,
                'em' => $this->instante($propina->cancelado_em, $fuso),
            ] : null,
            'anulacao' => $propina->estado === EstadoCobranca::ANULADA ? [
                'motivo' => $propina->motivo_anulacao,
                'por' => $propina->anuladoPor?->name,
                'em' => $this->instante($propina->anulado_em, $fuso),
            ] : null,
            'historico' => $this->historico($propina, $fuso),
            'pagamentos' => $this->pagamentos->daPropina($propina),
            'ajustes' => $propina->ajustes()->with(['alteracao', 'criadoPor', 'planoAnterior', 'planoNovo'])->get()
                ->map(fn (PropinaAjuste $ajuste) => [
                    'id' => $ajuste->id,
                    'em' => $this->instante($ajuste->created_at, $fuso),
                    'por' => $ajuste->criadoPor?->name,
                    'modo_descricao' => $ajuste->alteracao?->modo_descricao,
                    'mes_efeito' => $ajuste->alteracao?->mes_efeito === null ? null
                        : RotuloDePeriodo::competencia($ajuste->alteracao->mes_efeito->year, $ajuste->alteracao->mes_efeito->month),
                    'valor_anterior' => $ajuste->valor_anterior->unidadesMenores(),
                    'valor_novo' => $ajuste->valor_novo->unidadesMenores(),
                    'valor_pago_no_momento' => $ajuste->valor_pago_no_momento->unidadesMenores(),
                    'plano_anterior' => $ajuste->planoAnterior?->nome,
                    'plano_novo' => $ajuste->planoNovo?->nome,
                    'motivo' => $ajuste->motivo,
                ])->values()->all(),
            'cancelamento_bloqueado' => $this->cancelamento->motivoParaNaoCancelar($propina),
            'anulacao_bloqueada' => $this->anulacao->motivoParaNaoAnular($propina),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linha(Propina $propina, CarbonImmutable $hoje, string $fuso): array
    {
        $estado = $propina->estadoResolvido($hoje);
        $inicio = $propina->periodo_inicio->toDateString();
        $fim = $propina->periodo_fim->toDateString();
        $vencimento = $propina->data_vencimento->toDateString();

        return [
            'id' => $propina->id,
            'matricula_id' => $propina->matricula_id,
            'matricula_numero' => $propina->matricula?->numero_registo_matricula,
            'aluno_nome' => $propina->matricula?->aluno?->dadosPessoa?->nome_completo,
            'aluno_numero' => $propina->matricula?->aluno?->numero_matricula,
            'turma_nome' => $propina->matricula?->turma?->nome,
            'plano_propina_id' => $propina->plano_propina_id,
            'plano_nome' => $propina->plano?->nome,
            'plano_data_inicio' => $propina->plano?->data_inicio?->toDateString(),
            'ordem' => $propina->ordem,
            'periodo_inicio' => $inicio,
            'periodo_fim' => $fim,
            'periodo' => RotuloDePeriodo::periodo($inicio, $fim),
            'data_vencimento' => $vencimento,
            'data_limite' => $propina->data_limite->toDateString(),
            'valor' => $propina->valor->unidadesMenores(),
            'valor_pago' => $propina->valor_pago->unidadesMenores(),
            'saldo' => $propina->saldo()->unidadesMenores(),
            'multa' => $this->resumoMulta($propina->multa),
            'total_em_divida' => $propina->saldo()->unidadesMenores() + $propina->multaPendente(),
            'estado' => $estado->value,
            'estado_descricao' => $estado->label(),
            'origem_geracao' => $propina->origem_geracao->value,
            'origem_geracao_descricao' => $propina->origem_geracao_descricao,
            'motivo_geracao' => $propina->motivo_geracao,
            'geracao_tardia' => $this->geracaoTardia($propina, $fuso),
        ];
    }

    /** §8.1/Q4: retroactiva, ou criada (dia da escola) depois do vencimento. */
    private function geracaoTardia(Propina $propina, string $fuso): bool
    {
        $criadaNaEscola = $propina->created_at?->copy()->setTimezone($fuso)->toDateString();

        return $propina->origem_geracao === OrigemGeracaoPropina::RETROACTIVA
            || ($criadaNaEscola !== null && $criadaNaEscola > $propina->data_vencimento->toDateString());
    }

    /** @return array{valor: int, valor_pago: int, pendente: int, estado: int, estado_descricao: string, bloqueada_automatico: bool}|null */
    private function resumoMulta(?PropinaMulta $multa): ?array
    {
        if ($multa === null) {
            return null;
        }

        return [
            'valor' => $multa->valor->unidadesMenores(),
            'valor_pago' => $multa->valor_pago->unidadesMenores(),
            'pendente' => $multa->pendente()->unidadesMenores(),
            'estado' => $multa->estado->value,
            'estado_descricao' => $multa->estado->label(),
            'bloqueada_automatico' => $multa->bloqueada_automatico,
            // A regra que foi aplicada na altura (ex.: «20% · escalão 3»).
            'regra' => $multa->tipo === null ? null : Multa::descreverRegra($multa->tipo, (int) $multa->valor_regra, $multa->escalao_ordem, $this->moeda->atual()),
        ];
    }

    /**
     * Histórico simples, derivado das colunas da própria propina (D13). `por` nulo = sistema (comando/evento).
     *
     * @return list<array{evento: string, em: ?string, por: ?string, detalhe: ?string}>
     */
    private function historico(Propina $propina, string $fuso): array
    {
        $eventos = [[
            'evento' => 'Gerada',
            'em' => $this->instante($propina->created_at, $fuso),
            'por' => $propina->criadoPor?->name,
            'detalhe' => $propina->origem_geracao->label() . ($propina->motivo_geracao ? " — {$propina->motivo_geracao}" : ''),
        ]];

        if ($propina->cancelado_em !== null) {
            $eventos[] = [
                'evento' => 'Cancelada',
                'em' => $this->instante($propina->cancelado_em, $fuso),
                'por' => $propina->canceladoPor?->name,
                'detalhe' => $propina->motivo_cancelamento,
            ];
        }

        if ($propina->anulado_em !== null) {
            $eventos[] = [
                'evento' => 'Anulada',
                'em' => $this->instante($propina->anulado_em, $fuso),
                'por' => $propina->anuladoPor?->name,
                'detalhe' => $propina->motivo_anulacao,
            ];
        }

        return $eventos;
    }

    /** Um instante guardado na zona da aplicação, mostrado no fuso da escola. */
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
