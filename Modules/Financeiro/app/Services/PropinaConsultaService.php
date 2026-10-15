<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Support\AnulacaoDePropinas;
use Modules\Financeiro\Support\CancelamentoDePropinas;
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
    ) {
    }

    public function listar(array $filtros = []): LengthAwarePaginator
    {
        $hoje = $this->relogio->hoje();
        $fuso = $this->relogio->fuso();
        $estado = is_numeric($filtros['estado'] ?? null) ? EstadoCobranca::tryFrom((int) $filtros['estado']) : null;
        $origem = is_numeric($filtros['origem_geracao'] ?? null) ? OrigemGeracaoPropina::tryFrom((int) $filtros['origem_geracao']) : null;
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';

        return Propina::query()
            ->with(['matricula.aluno.dadosPessoa', 'matricula.turma', 'plano'])
            ->when(is_numeric($filtros['ano_lectivo_id'] ?? null), fn ($query) => $query->where('ano_lectivo_id', (int) $filtros['ano_lectivo_id']))
            ->when($estado !== null, fn ($query) => $query->comEstadoResolvido($estado, $hoje))
            ->when(is_numeric($filtros['plano_propina_id'] ?? null), fn ($query) => $query->where('plano_propina_id', (int) $filtros['plano_propina_id']))
            ->when(is_numeric($filtros['matricula_id'] ?? null), fn ($query) => $query->where('matricula_id', (int) $filtros['matricula_id']))
            ->when($origem !== null, fn ($query) => $query->where('origem_geracao', $origem->value))
            ->when($this->data($filtros['vencimento_de'] ?? null), fn ($query, string $dia) => $query->where('data_vencimento', '>=', $dia))
            ->when($this->data($filtros['vencimento_ate'] ?? null), fn ($query, string $dia) => $query->where('data_vencimento', '<=', $dia))
            ->when($pesquisa !== '', fn ($query) => $query->whereHas('matricula', fn ($matricula) => $matricula->where(fn ($grupo) => $grupo
                ->whereContem('numero_registo_matricula', $pesquisa)
                ->orWhereHas('aluno', fn ($aluno) => $aluno->where(fn ($subgrupo) => $subgrupo
                    ->whereContem('numero_matricula', $pesquisa)
                    ->orWhereHas('dadosPessoa', fn ($pessoa) => $pessoa->whereContem('nome_completo', $pesquisa)))))))
            ->orderBy('data_vencimento')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (Propina $propina) => $this->linha($propina, $hoje, $fuso));
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
            'matricula.aluno.dadosPessoa', 'matricula.turma', 'matricula.anoLectivo', 'plano', 'criadoPor', 'canceladoPor', 'anuladoPor',
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
            'valor' => $propina->valor->unidadesMenores(),
            'valor_pago' => $propina->valor_pago->unidadesMenores(),
            'saldo' => $propina->saldo()->unidadesMenores(),
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
            'ordem' => $propina->ordem,
            'periodo_inicio' => $inicio,
            'periodo_fim' => $fim,
            'periodo' => RotuloDePeriodo::periodo($inicio, $fim),
            'data_vencimento' => $vencimento,
            'data_limite' => $propina->data_limite->toDateString(),
            'valor' => $propina->valor->unidadesMenores(),
            'valor_pago' => $propina->valor_pago->unidadesMenores(),
            'saldo' => $propina->saldo()->unidadesMenores(),
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
