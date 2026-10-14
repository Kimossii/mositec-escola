<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoMatriculaDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Exceptions\GeracaoDePropinasDesactivada;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\ContextoDeGeracao;
use Modules\Financeiro\Support\ElegibilidadeDePeriodos;
use Modules\Financeiro\Support\ResolucaoDePlanosMemorizada;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\ResultadoResolucaoPlano;
use Modules\Financeiro\Support\RotuloDePeriodo;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Throwable;

/**
 * Ponto ÚNICO de criação de propinas (spec §7; CriadoresDePropinaTest). Serve a pré-visualização (só
 * leitura), a geração manual confirmada (GerarPropinasAction) e a automática (financeiro:gerar-propinas),
 * todas com o MESMO cálculo:
 *
 * 1. Só matrículas Activas, cuja turma é do ano lectivo da matrícula e do pedido.
 * 2. O plano decide-se mês a mês (L8): ResolvePlanoAplicavel::paraTurma($turma, $competencia), memorizado.
 * 3. Um período de um plano só se gera se TODOS os seus meses resolvem para esse plano (D1).
 * 4. Com um plano escolhido, os períodos de outro plano ficam ignorados — nunca se gera contra o
 *    resolvedor (L9).
 * 5. Período já coberto por uma propina activa da matrícula (qualquer plano, intervalos sobrepostos)
 *    fica ignorado (cobrança dupla, camada 2; a camada 1 são os índices únicos parciais de F1).
 * 6. Períodos anteriores ao mês da matrícula só com «incluir períodos anteriores», como RETROACTIVA (L19, Q9).
 * 7. Snapshot: valor do plano, regras de cobrança, moeda e câmbio do dia, todos no "hoje" da escola (§8.2b).
 */
class GerarPropinasService
{
    /** P5: máximo de matrículas por pedido de geração manual (pré-visualização ou confirmação). O comando não tem limite. */
    public const LIMITE_MATRICULAS_POR_PEDIDO = 500;

    public function __construct(
        private ResolvePlanoAplicavel $resolver,
        private ElegibilidadeDePeriodos $elegibilidade,
        private SnapshotMonetario $snapshot,
        private RelogioDoTenant $relogio,
        private TenantContext $tenant,
    ) {
    }

    /**
     * O que a geração faria agora. Nunca escreve (nem regras de cobrança por omissão).
     */
    public function preVisualizar(PedidoGeracaoPropinasDTO $pedido): ResultadoGeracaoPropinasDTO
    {
        $contexto = $this->contexto();
        $contexto->resolucao->sincronizar($pedido->ano_lectivo_id, bloquear: false);
        $resultado = new ResultadoGeracaoPropinasDTO(previsualizacao: true);

        // Eager loading em blocos: aluno.dadosPessoa e turma lidos por bloco, não matrícula a matrícula.
        foreach (array_chunk($this->idsDoPedido($pedido, $contexto), 100) as $ids) {
            $matriculas = Matricula::query()->with(['aluno.dadosPessoa', 'turma'])->whereIn('id', $ids)->orderBy('id')->get();

            foreach ($matriculas as $matricula) {
                $resultado->juntar($this->calcular($matricula, $pedido, $contexto, $this->activas((int) $matricula->id)));
            }
        }

        return $resultado;
    }

    /**
     * Grava as propinas em falta, matrícula a matrícula, cada uma na SUA transacção (spec §7.4): uma falha
     * reverte só essa matrícula e fica em `erros`; as outras ficam gravadas. Recalcula tudo sobre o estado
     * actual (a pré-visualização nunca é reutilizada, D4). Não chamar dentro de outra transacção: cada
     * matrícula tem de confirmar sozinha, e só assim os bloqueios são curtos.
     */
    public function gerar(PedidoGeracaoPropinasDTO $pedido): ResultadoGeracaoPropinasDTO
    {
        // Ponto único de criação: o interruptor de segurança (R-F2.1) e o ano encerrado travam aqui, também por defesa em profundidade.
        if (! GeracaoDePropinasDesactivada::activa()) {
            throw new GeracaoDePropinasDesactivada();
        }

        $this->recusarAnoEncerrado($pedido->ano_lectivo_id);

        $contexto = $this->contexto();
        // Fora de transacção, só para escolher as matrículas do âmbito; cada matrícula volta a sincronizar com bloqueio.
        $contexto->resolucao->sincronizar($pedido->ano_lectivo_id, bloquear: false);
        $resultado = new ResultadoGeracaoPropinasDTO(previsualizacao: false);

        foreach ($this->idsDoPedido($pedido, $contexto) as $id) {
            $resultado->juntar($this->gerarParaMatricula($id, $pedido, $contexto));
        }

        return $resultado;
    }

    /**
     * Geração automática (comando diário, spec §7.6): só se a escola ligou «gerar automaticamente»; todas as
     * matrículas Activas, ano lectivo a ano lectivo. Idempotente: o que falhou, ou ficou por gerar quando a
     * matrícula foi activada, é apanhado na execução seguinte (critério 3 de §8.2).
     *
     * @return array<int, ResultadoGeracaoPropinasDTO>|null  por ano lectivo; null = geração automática desligada
     */
    public function gerarAutomaticamente(): ?array
    {
        if (! (RegraCobranca::query()->first()?->gerar_automaticamente ?? false)) {
            return null;
        }

        $resultados = [];
        $anos = Matricula::query()
            ->where('estado', EstadoMatriculaEnum::ACTIVA->value)
            // Anos lectivos encerrados nunca geram propinas (spec §7).
            ->whereNotIn('ano_lectivo_id', AnoLectivo::query()->where('estado', EstadoAnoLectivo::ENCERRADO->value)->select('id'))
            ->distinct()
            ->orderBy('ano_lectivo_id')
            ->pluck('ano_lectivo_id');

        foreach ($anos as $anoLectivoId) {
            $resultados[(int) $anoLectivoId] = $this->gerar(PedidoGeracaoPropinasDTO::automatica((int) $anoLectivoId));
        }

        return $resultados;
    }

    private function recusarAnoEncerrado(int $anoLectivoId): void
    {
        if (AnoLectivo::query()->whereKey($anoLectivoId)->where('estado', EstadoAnoLectivo::ENCERRADO->value)->exists()) {
            throw ValidationException::withMessages(['ano_lectivo_id' => 'Não é possível gerar propinas para um ano lectivo encerrado.']);
        }
    }

    private function gerarParaMatricula(int $id, PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): ResultadoGeracaoMatriculaDTO
    {
        try {
            return DB::transaction(fn () => $this->gravarMatricula($id, $pedido, $contexto));
        } catch (Throwable $e) {
            // Já FORA da transacção, revertida por inteiro: nada desta matrícula ficou gravado. Lá dentro
            // nunca se apanha nada (o PostgreSQL abortaria a transacção).
            return $this->falhou($id, $pedido, $e);
        }
    }

    private function gravarMatricula(int $id, PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): ResultadoGeracaoMatriculaDTO
    {
        // 1. A matrícula primeiro (D2): duas gerações da mesma matrícula fazem fila aqui, uma mudança de estado
        //    da matrícula espera por esta transacção, e inserir propinas dela noutra ligação também (FK → KEY SHARE).
        $matricula = Matricula::query()->whereKey($id)->lockForUpdate()->first();

        if ($matricula === null) {
            $resultado = new ResultadoGeracaoMatriculaDTO(matricula_id: $id);
            $resultado->ignorada('A matrícula já não existe.');

            return $resultado;
        }

        $matricula->load(['aluno.dadosPessoa', 'turma']);

        // 2. Os planos do ano em modo partilhado (L15): a divisão de um plano (F4) espera por nós; uma divisão já
        //    confirmada muda a impressão e a memória do resolvedor é esquecida.
        $contexto->resolucao->sincronizar((int) $matricula->ano_lectivo_id, bloquear: true);

        // 3. Calcular sobre o estado bloqueado e criar com Eloquent, uma a uma (L4: nada de inserções que ignorem
        //    conflitos; PertenceAoTenant e as invariantes do model correm). Os índices únicos parciais de F1 são a rede de segurança.
        $resultado = $this->calcular($matricula, $pedido, $contexto, $this->activas((int) $matricula->id));

        foreach ($resultado->criadas() as $indice => $item) {
            $propina = Propina::create([
                'matricula_id' => $matricula->id,
                'plano_propina_id' => $item['plano_propina_id'],
                'ano_lectivo_id' => $matricula->ano_lectivo_id,
                'ordem' => $item['ordem'],
                'periodo_inicio' => $item['periodo_inicio'],
                'periodo_fim' => $item['periodo_fim'],
                'valor' => Dinheiro::deUnidadesMenores($item['valor']),
                'moeda' => $contexto->moeda,
                'cambio_usd' => $contexto->cambio_usd,
                'data_vencimento' => $item['data_vencimento'],
                'dias_tolerancia' => $item['dias_tolerancia'],
                'origem_geracao' => OrigemGeracaoPropina::from($item['origem_geracao']),
                'motivo_geracao' => $item['motivo_geracao'],
            ]);

            $resultado->criada($indice, (int) $propina->id);
        }

        return $resultado;
    }

    private function falhou(int $id, PedidoGeracaoPropinasDTO $pedido, Throwable $e): ResultadoGeracaoMatriculaDTO
    {
        // Só a violação dos índices únicos de propinas é concorrência; qualquer outra (mesmo de unicidade) é um defeito.
        $concorrencia = ViolacaoDeUnicidade::dasPropinas($e);

        if (! $concorrencia) {
            report($e);
        }

        // Log estruturado (critério 3 de §8.2): o comando diário volta a tentar esta matrícula.
        Log::warning('financeiro.gerar-propinas.matricula-falhou', [
            'tenant_id' => $this->tenant->id(),
            'matricula_id' => $id,
            'origem' => $pedido->origem->name,
            'excepcao' => $e::class,
            'concorrencia' => $concorrencia,
            'mensagem' => $e->getMessage(),
        ]);

        $matricula = Matricula::query()->with(['aluno.dadosPessoa', 'turma'])->find($id);
        $resultado = $matricula !== null ? ResultadoGeracaoMatriculaDTO::de($matricula) : new ResultadoGeracaoMatriculaDTO(matricula_id: $id);
        $resultado->erro($concorrencia
            ? 'Outra geração gravou propinas desta matrícula ao mesmo tempo: nada foi gravado aqui. Volte a gerar para confirmar.'
            : 'Erro inesperado ao gerar as propinas desta matrícula: nada foi gravado. Consulte os registos.');

        return $resultado;
    }

    private function contexto(): ContextoDeGeracao
    {
        // Leitura pura: RegraCobranca::doTenant() faria firstOrCreate. Sem linha valem os valores por omissão.
        $regras = RegraCobranca::query()->first();
        $hoje = $this->relogio->hoje();
        $snapshot = $this->snapshot->em($hoje);

        return new ContextoDeGeracao(
            resolucao: new ResolucaoDePlanosMemorizada($this->resolver),
            hoje: $hoje,
            // Dados corrompidos (dia 31) nunca podem transbordar para o mês seguinte: 1–28.
            dia_vencimento: min(28, max(1, (int) ($regras?->dia_vencimento ?? RegraCobranca::DEFAULTS['dia_vencimento']))),
            dias_tolerancia: (int) ($regras?->dias_tolerancia ?? RegraCobranca::DEFAULTS['dias_tolerancia']),
            moeda: $snapshot['moeda'],
            cambio_usd: $snapshot['cambio_usd'],
        );
    }

    /**
     * Matrículas do âmbito, por id crescente (ordem estável). O comando só lê as Activas; os âmbitos
     * manuais trazem todas, para as não Activas aparecerem como ignoradas com o motivo.
     *
     * @return list<int>
     */
    private function idsDoAmbito(PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): array
    {
        $consulta = Matricula::query()->where('ano_lectivo_id', $pedido->ano_lectivo_id);

        match ($pedido->ambito) {
            AmbitoGeracaoPropinas::MATRICULA => $consulta->where('numero_registo_matricula', $pedido->matricula_numero),
            AmbitoGeracaoPropinas::TURMA => $consulta->where('turma_id', $pedido->alvo_id),
            AmbitoGeracaoPropinas::NIVEL => $consulta->whereHas('turma', fn ($turma) => $turma->where('nivel_academico_id', $pedido->alvo_id)),
            AmbitoGeracaoPropinas::PLANO => $consulta->whereIn('turma_id', $this->turmasDoPlano($pedido, $contexto)),
            AmbitoGeracaoPropinas::TODAS => $consulta->where('estado', EstadoMatriculaEnum::ACTIVA->value),
        };

        return $consulta->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * As matrículas do âmbito, recusando (antes de calcular ou gravar) um pedido manual grande demais para
     * um pedido HTTP (R-F2.4). Em `ambito` para o formulário mostrar a mensagem junto de "Para quem".
     *
     * @return list<int>
     */
    private function idsDoPedido(PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): array
    {
        $ids = $this->idsDoAmbito($pedido, $contexto);

        if ($pedido->ambito !== AmbitoGeracaoPropinas::TODAS && count($ids) > self::LIMITE_MATRICULAS_POR_PEDIDO) {
            throw ValidationException::withMessages([
                'ambito' => 'O âmbito escolhido tem ' . count($ids) . ' matrículas; a geração manual trata no máximo '
                    . self::LIMITE_MATRICULAS_POR_PEDIDO . ' por pedido. ' . $this->sugestaoDeLimite($pedido->ambito),
            ]);
        }

        return $ids;
    }

    private function sugestaoDeLimite(AmbitoGeracaoPropinas $ambito): string
    {
        return match ($ambito) {
            AmbitoGeracaoPropinas::TURMA => 'Seleccione matrículas individuais.',
            AmbitoGeracaoPropinas::NIVEL => 'Restrinja por turma.',
            default => 'Restrinja por turma ou por nível académico.',
        };
    }

    /**
     * Turmas do ano a que o plano se aplica em pelo menos um dos seus meses (âmbito "todas as do plano").
     *
     * @return list<int>
     */
    private function turmasDoPlano(PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): array
    {
        $plano = PlanoPropina::query()->with('anoLectivo')->find($pedido->plano_propina_id);

        if ($plano === null) {
            return [];
        }

        $ids = [];

        foreach (Turma::query()->where('ano_lectivo_id', $pedido->ano_lectivo_id)->orderBy('id')->get() as $turma) {
            foreach ($plano->competencias() as $competencia) {
                if ($contexto->resolucao->paraTurma($turma, $competencia['ano'], $competencia['mes'])->plano?->id === $plano->id) {
                    $ids[] = (int) $turma->id;

                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * @return Collection<int, Propina>
     */
    private function activas(int $matriculaId): Collection
    {
        return Propina::query()
            ->activas()
            ->where('matricula_id', $matriculaId)
            ->with('plano:id,nome')
            ->orderBy('periodo_inicio')
            ->get();
    }

    /**
     * O cálculo partilhado pela pré-visualização e pela geração. Não escreve.
     *
     * @param  Collection<int, Propina>  $activas  propinas activas (Em Aberto, Parcial, Paga) da matrícula
     */
    private function calcular(Matricula $matricula, PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto, Collection $activas): ResultadoGeracaoMatriculaDTO
    {
        $resultado = ResultadoGeracaoMatriculaDTO::de($matricula);

        if ($matricula->estado !== EstadoMatriculaEnum::ACTIVA) {
            $resultado->ignorada("A matrícula está {$matricula->estado?->label()}: só se geram propinas para matrículas activas.");

            return $resultado;
        }

        $turma = $matricula->turma;

        if ($turma === null) {
            $resultado->ignorada('A matrícula não tem turma (a turma pode ter sido eliminada).');

            return $resultado;
        }

        // Invariante de §3: plano, matrícula e turma do mesmo ano lectivo (o resolvedor só vê planos do ano da turma).
        if ((int) $matricula->ano_lectivo_id !== $pedido->ano_lectivo_id || (int) $turma->ano_lectivo_id !== (int) $matricula->ano_lectivo_id) {
            $resultado->ignorada('A matrícula, a turma e o pedido têm de ser do mesmo ano lectivo: corrija a matrícula.');

            return $resultado;
        }

        $porMes = $this->resolverMeses($turma, $contexto, $resultado);

        foreach ($this->periodosCandidatos($porMes, $resultado) as $candidato) {
            $this->avaliarPeriodo($matricula, $candidato['plano'], $candidato['periodo'], $pedido, $contexto, $activas, $resultado);
        }

        return $resultado;
    }

    /**
     * L8: o plano aplicável decide-se mês a mês. Conflitos e meses sem plano ficam no resultado.
     *
     * @return array<int, ResultadoResolucaoPlano> ordinal (ano × 12 + mês) => resolução
     */
    private function resolverMeses(Turma $turma, ContextoDeGeracao $contexto, ResultadoGeracaoMatriculaDTO $resultado): array
    {
        $porMes = [];

        foreach ($contexto->resolucao->mesesCobraveis((int) $turma->ano_lectivo_id) as $competencia) {
            $resolucao = $contexto->resolucao->paraTurma($turma, $competencia['ano'], $competencia['mes']);
            $porMes[$this->ordinal($competencia)] = $resolucao;
            $rotulo = RotuloDePeriodo::competencia($competencia['ano'], $competencia['mes']);

            if ($resolucao->conflito) {
                $resultado->conflito("{$rotulo}: os planos {$this->nomes($resolucao->candidatos)} aplicam-se com a mesma precedência. Corrija os alvos dos planos.");
            } elseif ($resolucao->plano === null) {
                $resultado->semPlano($rotulo);
            }
        }

        return $porMes;
    }

    /**
     * Períodos dos planos resolvidos cujos meses resolvem TODOS para esse plano, por ordem de início (D1).
     * Um período com meses de outro plano (ou em conflito, ou sem plano) não se gera e fica como conflito:
     * cobrá-lo inteiro cobraria meses de outro plano, e cortá-lo mudaria o calendário do plano.
     *
     * @param  array<int, ResultadoResolucaoPlano>  $porMes
     * @return list<array{plano: PlanoPropina, periodo: array{ordem: int, meses: int, inicio: string, fim: string}}>
     */
    private function periodosCandidatos(array $porMes, ResultadoGeracaoMatriculaDTO $resultado): array
    {
        $planos = [];

        foreach ($porMes as $resolucao) {
            if ($resolucao->temPlano()) {
                $planos[(int) $resolucao->plano->id] = $resolucao->plano;
            }
        }

        ksort($planos);
        $candidatos = [];

        foreach ($planos as $plano) {
            $periodos = $plano->periodos();

            foreach (array_chunk($plano->competencias(), $plano->intervalo_meses) as $indice => $meses) {
                $doPlano = array_filter($meses, fn (array $competencia) => ($porMes[$this->ordinal($competencia)] ?? null)?->plano?->id === $plano->id);

                if ($doPlano === []) {
                    continue;
                }

                $periodo = $periodos[$indice];

                if (count($doPlano) !== count($meses)) {
                    $resultado->conflito('O período ' . RotuloDePeriodo::periodo($periodo['inicio'], $periodo['fim'])
                        . " do plano «{$plano->nome}» inclui meses em que se aplica outro plano (ou nenhum): esse período não foi gerado. Reveja os alvos e os meses dos planos.");

                    continue;
                }

                $candidatos[] = ['plano' => $plano, 'periodo' => $periodo];
            }
        }

        usort($candidatos, fn (array $a, array $b) => [$a['periodo']['inicio'], $a['plano']->id] <=> [$b['periodo']['inicio'], $b['plano']->id]);

        return $candidatos;
    }

    /**
     * @param  array{ordem: int, meses: int, inicio: string, fim: string}  $periodo
     * @param  Collection<int, Propina>  $activas
     */
    private function avaliarPeriodo(
        Matricula $matricula,
        PlanoPropina $plano,
        array $periodo,
        PedidoGeracaoPropinasDTO $pedido,
        ContextoDeGeracao $contexto,
        Collection $activas,
        ResultadoGeracaoMatriculaDTO $resultado,
    ): void {
        $rotulo = RotuloDePeriodo::periodo($periodo['inicio'], $periodo['fim']);

        // L9: nunca se gera contra o resolvedor; com um plano escolhido, os períodos de outro plano ficam de fora.
        if ($pedido->plano_propina_id !== null && (int) $plano->id !== $pedido->plano_propina_id) {
            $resultado->ignorada("Aplica-se outro plano a este período: «{$plano->nome}».", $rotulo, $plano->nome);

            return;
        }

        // Cobrança dupla, camada 2 (spec §3, §14 "regras fechadas"): propina activa da matrícula, de qualquer plano, sobreposta.
        $cobertura = $activas->first(fn (Propina $propina) => $propina->periodo_inicio->toDateString() <= $periodo['fim']
            && $propina->periodo_fim->toDateString() >= $periodo['inicio']);

        if ($cobertura !== null) {
            $resultado->ignorada(
                'Já coberto pela propina de ' . RotuloDePeriodo::periodo($cobertura->periodo_inicio->toDateString(), $cobertura->periodo_fim->toDateString())
                    . " (plano «{$cobertura->plano?->nome}»).",
                $rotulo,
                $plano->nome,
            );

            return;
        }

        $anterior = $this->elegibilidade->anteriorAMatricula($matricula->data_matricula, $periodo['fim']);

        if ($anterior && ! $pedido->incluir_periodos_anteriores) {
            $resultado->ignorada(
                'Anterior ao mês da matrícula (' . $matricula->data_matricula->format('d/m/Y') . '): só se gera com «incluir períodos anteriores».',
                $rotulo,
                $plano->nome,
            );

            return;
        }

        $origem = $anterior ? OrigemGeracaoPropina::RETROACTIVA : $pedido->origem;
        // Spec §5: dia de vencimento das regras no mês de início do período (1–28, sempre válido).
        $vencimento = CarbonImmutable::createFromFormat('!Y-m-d', $periodo['inicio'])->setDay($contexto->dia_vencimento);

        $resultado->aCriar([
            'plano_propina_id' => (int) $plano->id,
            'plano' => $plano->nome,
            'ordem' => $periodo['ordem'],
            'periodo_inicio' => $periodo['inicio'],
            'periodo_fim' => $periodo['fim'],
            'periodo' => $rotulo,
            'meses' => $periodo['meses'],
            'valor' => $plano->valor->unidadesMenores(),
            'data_vencimento' => $vencimento->toDateString(),
            'dias_tolerancia' => $contexto->dias_tolerancia,
            'data_limite' => $vencimento->addDays($contexto->dias_tolerancia)->toDateString(),
            'origem_geracao' => $origem->value,
            'origem_geracao_descricao' => $origem->label(),
            'motivo_geracao' => $origem->exigeMotivo() ? $pedido->motivo_geracao : null,
            // Geração tardia (§8.1/Q4): nasce depois do seu vencimento. Dia da escola, não de UTC (§8.2b).
            'tardia' => $contexto->hoje->toDateString() > $vencimento->toDateString(),
        ]);
    }

    /**
     * @param  array{ano: int, mes: int}  $competencia
     */
    private function ordinal(array $competencia): int
    {
        return $competencia['ano'] * 12 + $competencia['mes'];
    }

    /**
     * @param  list<PlanoPropina>  $planos
     */
    private function nomes(array $planos): string
    {
        return implode(', ', array_map(fn (PlanoPropina $plano) => "«{$plano->nome}»", $planos));
    }
}
