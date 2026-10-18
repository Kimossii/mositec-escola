<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\DTO\AlterarPrecoPlanoDTO;
use Modules\Financeiro\DTO\PreVisualizacaoAlteracaoPrecoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\CalendarioDePlano;
use Modules\Financeiro\Support\RotuloDePeriodo;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

/**
 * Pré-visualização da alteração de preço de um plano com propinas (spec Propinas §14). SÓ LÊ. É o mesmo cálculo
 * que a AlterarPrecoPlanoAction refaz sobre o estado bloqueado (a impressão digital tem de coincidir).
 *
 * - O conjunto parte SEMPRE de `propinas.plano_propina_id = plano` e `periodo_inicio >= mes_efeito` (isolamento por
 *   ano lectivo: nunca por turma, alvos ou aluno). Propinas do plano de outro ano → recusa total (D9).
 * - `mes_efeito` = início de um período do plano (no calendário do seu ano lectivo); o 1.º período = in loco (D4).
 * - Com divisão, o plano novo tem de continuar exactamente os meses do antigo (âncora L6) e cada propina a
 *   reassociar tem de ter período no plano novo.
 * - APLICAR altera Em Aberto/Parcialmente Paga (`valor = max(novo, pago)`); Pagas, Canceladas e Anuladas ficam.
 */
class AlteracaoPrecoPlanoService
{
    public const VERSAO_IMPRESSAO = 1;

    private const MOTIVO_JA_TEM_O_VALOR = 'Já tem este valor.';

    public const NOME_MAXIMO = 100;

    public const MENSAGEM_SEM_PROPINAS = 'Este plano ainda não tem propinas geradas: altere o valor em «Editar».';

    public const MENSAGEM_ANO_ENCERRADO = 'Não é possível alterar o preço de um plano de um ano lectivo encerrado.';

    public const MENSAGEM_VALOR_IGUAL = 'O novo valor tem de ser diferente do valor actual do plano.';

    public const MENSAGEM_OUTRO_ANO = 'Foram encontradas propinas de outro ano lectivo associadas a este plano. A operação foi cancelada; contacte o suporte.';

    public function __construct(
        private TenantContext $tenant,
        private ResolvePlanoAplicavel $resolver,
    ) {
    }

    public function preVisualizar(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto): PreVisualizacaoAlteracaoPrecoDTO
    {
        $plano->loadMissing(['anoLectivo', 'alvos']);
        $mesEfeito = $dto->mesEfeito->startOfMonth();
        $dia = $mesEfeito->toDateString();
        $novo = $dto->valorNovo->unidadesMenores();

        if (! $plano->propinas()->exists()) {
            throw ValidationException::withMessages(['valor' => self::MENSAGEM_SEM_PROPINAS]);
        }

        // Decisão do dono: planos de anos encerrados ficam bloqueados (mesma consulta de «ano encerrado» da geração).
        if (AnoLectivo::query()->whereKey($plano->ano_lectivo_id)->where('estado', EstadoAnoLectivo::ENCERRADO->value)->exists()) {
            throw ValidationException::withMessages(['plano' => self::MENSAGEM_ANO_ENCERRADO]);
        }

        if ($plano->valor->unidadesMenores() === $novo) {
            throw ValidationException::withMessages(['valor' => self::MENSAGEM_VALOR_IGUAL]);
        }

        $this->recusarPropinasDeOutrosAnos($plano);

        $periodos = $plano->periodos();
        $indice = array_search($dia, array_column($periodos, 'inicio'), true);

        if ($indice === false) {
            throw ValidationException::withMessages(['mes_efeito' => 'O mês de efeito tem de ser o início de um período deste plano, no seu ano lectivo ('
                . implode(', ', array_map(fn (array $p) => $this->rotuloDoMes($p['inicio']), $periodos)) . ').']);
        }

        $inLoco = $indice === 0;
        $novosPeriodos = $inLoco ? [] : $this->periodosDoPlanoNovo($plano, $mesEfeito);
        $ordemNoNovo = array_column($novosPeriodos, 'ordem', 'inicio');

        $candidatas = Propina::query()
            ->where('plano_propina_id', $plano->id)
            ->where('periodo_inicio', '>=', $dia)
            ->with(['matricula.aluno.dadosPessoa'])
            ->orderBy('periodo_inicio')
            ->orderBy('id')
            ->get();

        $afectadas = [];
        $inalteradas = [];
        $reassociadasSemAjuste = [];

        foreach ($candidatas as $propina) {
            $linha = $this->linha($propina);
            $pago = $linha['valor_pago'];
            $depois = max($novo, $pago);
            $motivo = $this->motivoParaFicarInalterada($propina, $dto->modo);
            $jaTemOValor = $motivo === null && $depois === $linha['valor_antes'];

            if ($jaTemOValor) {
                $motivo = self::MOTIVO_JA_TEM_O_VALOR;
            }

            // D6: com divisão, uma Em Aberto/Parcial que já tem o valor novo FICA no plano antigo, cujo calendário foi
            // encurtado e já não a contém. Passa ao plano novo sem mudar de valor e sem ajuste.
            if ($jaTemOValor && ! $inLoco && $dto->modo === ModoAlteracaoPreco::APLICAR_GERADAS) {
                $ordemDepois = $ordemNoNovo[$linha['periodo_inicio']] ?? null;

                if ($ordemDepois === null) {
                    throw ValidationException::withMessages(['mes_efeito' => "A propina de {$linha['periodo']} (matrícula {$linha['matricula_numero']}) não corresponde a nenhum período do plano novo: escolha outro mês de efeito ou o início do plano."]);
                }

                $reassociadasSemAjuste[] = $linha + ['ordem_depois' => (int) $ordemDepois];

                continue;
            }

            if ($motivo !== null) {
                $inalteradas[] = $linha + ['motivo' => $motivo];

                continue;
            }

            $ordemDepois = $inLoco ? $linha['ordem_antes'] : ($ordemNoNovo[$linha['periodo_inicio']] ?? null);

            if ($ordemDepois === null) {
                throw ValidationException::withMessages(['mes_efeito' => "A propina de {$linha['periodo']} (matrícula {$linha['matricula_numero']}) não corresponde a nenhum período do plano novo: escolha outro mês de efeito ou o início do plano."]);
            }

            $afectadas[] = $linha + [
                'ordem_depois' => (int) $ordemDepois,
                'valor_depois' => $depois,
                'saldo_depois' => $depois - $pago,
                'excesso' => max(0, $pago - $novo),
                'passa_a_paga' => $pago > 0 && $depois === $pago,
            ];
        }

        $nomeNovo = $inLoco ? null : $this->nomeDoPlanoNovo($plano, $mesEfeito);

        return new PreVisualizacaoAlteracaoPrecoDTO(
            modo: $dto->modo,
            mesEfeito: $dia,
            inLoco: $inLoco,
            anoLectivo: ['id' => (int) $plano->ano_lectivo_id, 'nome' => $plano->anoLectivo?->nome],
            propinasDeOutrosAnos: 0,
            valorAnterior: $plano->valor->unidadesMenores(),
            valorNovo: $novo,
            nomePlanoNovo: $nomeNovo,
            planos: $inLoco
                ? [$this->descricaoDoPlano('mesmo', $plano->nome, $novo, $plano->data_inicio, $plano->data_fim, $periodos)]
                : [
                    $this->descricaoDoPlano('antigo', $plano->nome, $plano->valor->unidadesMenores(), $plano->data_inicio, $mesEfeito->subDay(), array_slice($periodos, 0, $indice)),
                    $this->descricaoDoPlano('novo', (string) $nomeNovo, $novo, $mesEfeito, $plano->data_fim, $novosPeriodos),
                ],
            afectadas: $afectadas,
            inalteradas: $inalteradas,
            reassociadasSemAjuste: $reassociadasSemAjuste,
            totais: $this->totais($plano, $dia, $afectadas, $inalteradas, $reassociadasSemAjuste),

            avisoValoresDiferentes: $this->avisoValoresDiferentes($plano, $dto->modo, $mesEfeito, $candidatas),
            impressaoDigital: $this->impressaoDigital($plano, $dto, $candidatas, array_column($reassociadasSemAjuste, 'propina_id')),
        );
    }

    /**
     * "{nome} (desde MM/AAAA)", sem repetir um sufixo de uma divisão anterior, cortado para caber em 100 caracteres e
     * com " 2", " 3"… se o nome já existir no ano lectivo do plano.
     */
    public function nomeDoPlanoNovo(PlanoPropina $plano, CarbonImmutable $mesEfeito): string
    {
        $base = (string) preg_replace('/ \(desde \d{2}\/\d{4}\)( \d+)?$/u', '', $plano->nome);
        $sufixo = ' (desde ' . $mesEfeito->format('m/Y') . ')';
        $n = 1;

        while (true) {
            $final = $sufixo . ($n === 1 ? '' : " {$n}");
            $nome = mb_substr($base, 0, self::NOME_MAXIMO - mb_strlen($final)) . $final;

            if (! PlanoPropina::query()->where('ano_lectivo_id', $plano->ano_lectivo_id)->where('nome', $nome)->exists()) {
                return $nome;
            }

            $n++;
        }
    }

    /**
     * M1 (fases F4, spec §14 escolha 2): com «Manter as já geradas», os alunos com propinas já geradas ficam ao valor
     * antigo e os que ainda não as têm nascem ao valor novo, no mesmo mês e turma. Contagens reais, para a escola
     * decidir sabendo o efeito.
     *
     * @param  Collection<int, Propina>  $candidatas
     * @return array{matriculas_valor_antigo: int, matriculas_valor_novo: int, turma_exemplo: ?string, mensagem: string}|null
     */
    private function avisoValoresDiferentes(PlanoPropina $plano, ModoAlteracaoPreco $modo, CarbonImmutable $mesEfeito, Collection $candidatas): ?array
    {
        if ($modo !== ModoAlteracaoPreco::MANTER_GERADAS) {
            return null;
        }

        $dia = $mesEfeito->toDateString();
        $comValorAntigo = $candidatas
            ->filter(fn (Propina $p) => in_array($p->estado->value, Propina::ESTADOS_ACTIVOS, true))
            ->map(fn (Propina $p) => (int) $p->matricula_id)
            ->unique()
            ->values();

        // Os planos do ano carregam-se UMA vez (o resolvedor recarregá-los-ia por turma).
        $planosDoAno = $this->resolver->planosActivosDoAno((int) $plano->ano_lectivo_id);
        $turmas = Turma::query()
            ->where('ano_lectivo_id', $plano->ano_lectivo_id)
            ->orderBy('id')
            ->get()
            ->filter(fn (Turma $turma) => $this->resolver->paraTurma($turma, ['ano' => $mesEfeito->year, 'mes' => $mesEfeito->month], $planosDoAno)->plano?->id === $plano->id)
            ->values();

        $cobertas = Propina::query()->activas()->where('periodo_inicio', '<=', $dia)->where('periodo_fim', '>=', $dia)->select('matricula_id');
        $semPropina = Matricula::query()
            ->where('ano_lectivo_id', $plano->ano_lectivo_id)
            ->where('estado', EstadoMatriculaEnum::ACTIVA->value)
            ->whereIn('turma_id', $turmas->modelKeys())
            ->whereNotIn('id', $cobertas)
            ->get(['id', 'turma_id']);

        $turmaDosAntigos = Matricula::query()->whereIn('id', $comValorAntigo->all())->pluck('turma_id')->map(fn ($id) => (int) $id)->unique();
        $exemplo = $turmas->first(fn (Turma $turma) => $turmaDosAntigos->contains((int) $turma->id) && $semPropina->contains('turma_id', $turma->id));
        $rotulo = $this->rotuloDoMes($dia);

        return [
            'matriculas_valor_antigo' => $comValorAntigo->count(),
            'matriculas_valor_novo' => $semPropina->count(),
            'turma_exemplo' => $exemplo?->nome,
            'mensagem' => "Com «Manter as já geradas», {$comValorAntigo->count()} matrícula(s) mantêm as propinas já geradas ao valor anterior e "
                . "{$semPropina->count()} matrícula(s) activa(s) ainda sem propina em {$rotulo} pagarão o novo valor: no mesmo mês e turma haverá alunos a pagar valores diferentes."
                . ($exemplo !== null ? " Exemplo: turma {$exemplo->nome}." : ''),
        ];
    }

    /** Defesa em profundidade (spec §14, isolamento): uma propina do plano de outro ano aborta a operação inteira. */
    private function recusarPropinasDeOutrosAnos(PlanoPropina $plano): void
    {
        $anoId = (int) $plano->ano_lectivo_id;
        $ids = Propina::query()
            ->where('plano_propina_id', $plano->id)
            ->where(fn ($q) => $q->where('ano_lectivo_id', '!=', $anoId)
                // Matrículas apagadas contam (D7): um soft-delete não pode esconder uma propina de outro ano.
                ->orWhereHas('matricula', fn ($m) => $m->withTrashed()->where('ano_lectivo_id', '!=', $anoId)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return;
        }

        Log::error('financeiro.alterar-preco.propinas-de-outro-ano', [
            'tenant_id' => $this->tenant->id(),
            'plano_propina_id' => (int) $plano->id,
            'ano_lectivo_id' => $anoId,
            'propina_ids' => $ids,
        ]);

        throw ValidationException::withMessages(['plano' => self::MENSAGEM_OUTRO_ANO]);
    }

    /**
     * As competências do plano novo derivam directamente das datas (de mes_efeito até ao fim do plano);
     * o ano vem das datas, por isso o calendário coincide por construção com os meses que restam do antigo.
     *
     * @return list<array{ordem: int, meses: int, inicio: string, fim: string}>
     */
    private function periodosDoPlanoNovo(PlanoPropina $plano, CarbonImmutable $mesEfeito): array
    {
        return CalendarioDePlano::periodos($mesEfeito, $plano->data_fim, (int) $plano->intervalo_meses);
    }

    private function motivoParaFicarInalterada(Propina $propina, ModoAlteracaoPreco $modo): ?string
    {
        return match (true) {
            $propina->estado === EstadoCobranca::PAGA => 'Paga: nunca é alterada.',
            $propina->estado === EstadoCobranca::CANCELADA => 'Cancelada: nunca é alterada.',
            $propina->estado === EstadoCobranca::ANULADA => 'Anulada: nunca é alterada.',
            $modo === ModoAlteracaoPreco::MANTER_GERADAS => 'Mantida no valor anterior (escolha «Manter as já geradas»).',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function linha(Propina $propina): array
    {
        $valor = $propina->valor->unidadesMenores();
        $pago = $propina->valor_pago->unidadesMenores();
        $inicio = $propina->periodo_inicio->toDateString();

        return [
            'propina_id' => (int) $propina->id,
            'matricula_numero' => $propina->matricula?->numero_registo_matricula,
            'aluno_nome' => $propina->matricula?->aluno?->dadosPessoa?->nome_completo,
            'periodo' => RotuloDePeriodo::periodo($inicio, $propina->periodo_fim->toDateString()),
            'periodo_inicio' => $inicio,
            'estado' => $propina->estado->value,
            'estado_descricao' => $propina->estado->label(),
            'ordem_antes' => (int) $propina->ordem,
            'valor_antes' => $valor,
            'valor_pago' => $pago,
            'saldo_antes' => $valor - $pago,
        ];
    }

    /**
     * @param  list<array{ordem: int, meses: int, inicio: string, fim: string}>  $periodos
     * @return array<string, mixed>
     */
    private function descricaoDoPlano(string $papel, string $nome, int $valor, CarbonInterface $dataInicio, CarbonInterface $dataFim, array $periodos): array
    {
        return [
            'papel' => $papel,
            'nome' => $nome,
            'valor' => $valor,
            'data_inicio' => $dataInicio->toDateString(),
            'data_fim' => $dataFim->toDateString(),
            'periodos' => array_map(fn (array $p) => [
                'ordem' => $p['ordem'],
                'periodo' => RotuloDePeriodo::periodo($p['inicio'], $p['fim']),
                'meses' => $p['meses'],
            ], array_values($periodos)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $afectadas
     * @param  list<array<string, mixed>>  $inalteradas
     * @param  list<array<string, mixed>>  $reassociadasSemAjuste
     * @return array<string, int>
     */
    private function totais(PlanoPropina $plano, string $dia, array $afectadas, array $inalteradas, array $reassociadasSemAjuste): array
    {
        return [
            'afectadas' => count($afectadas),
            'inalteradas' => count($inalteradas),
            'reassociadas_sem_ajuste' => count($reassociadasSemAjuste),
            'anteriores_ao_mes_efeito' => Propina::query()->where('plano_propina_id', $plano->id)->where('periodo_inicio', '<', $dia)->count(),
            'valor_antes' => array_sum(array_column($afectadas, 'valor_antes')),
            'valor_depois' => array_sum(array_column($afectadas, 'valor_depois')),
            'recebido' => array_sum(array_column($afectadas, 'valor_pago')),
            'saldo_antes' => array_sum(array_column($afectadas, 'saldo_antes')),
            'saldo_depois' => array_sum(array_column($afectadas, 'saldo_depois')),
            'excesso' => array_sum(array_column($afectadas, 'excesso')),
        ];
    }

    /**
     * D8 (spec §14 "Confirmação idempotente"; fases L16): parâmetros, estado do plano (incluindo o ano lectivo e os
     * alvos) e cada candidata (ids, valores, valor pago e estado). Muda se alguém pagar, anular, gerar ou dividir.
     *
     * @param  Collection<int, Propina>  $candidatas
     * @param  list<int>  $reassociadasSemAjuste  ids que passam ao plano novo sem mudar de valor (D6)
     */
    public function impressaoDigital(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto, Collection $candidatas, array $reassociadasSemAjuste = []): string
    {
        $plano->loadMissing(['anoLectivo', 'alvos']);
        $ano = $plano->anoLectivo;
        $alvos = $plano->alvos
            ->map(fn (PlanoPropinaAlvo $alvo) => AlvosDoPlano::chave($alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'])))
            ->sort()
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'versao' => self::VERSAO_IMPRESSAO,
            'modo' => $dto->modo->value,
            'mes_efeito' => $dto->mesEfeito->startOfMonth()->toDateString(),
            'valor_novo' => $dto->valorNovo->unidadesMenores(),
            'plano' => [
                'id' => (int) $plano->id,
                'ano_lectivo_id' => (int) $plano->ano_lectivo_id,
                'ano_data_inicio' => $ano?->data_inicio?->toDateString(),
                'ano_estado' => $ano?->estado?->value,
                'nome' => $plano->nome,
                'descricao' => $plano->descricao,
                'valor' => $plano->valor->unidadesMenores(),
                'periodicidade' => $plano->periodicidade?->value,
                'intervalo_meses' => (int) $plano->intervalo_meses,
                'data_inicio' => $plano->data_inicio?->toDateString(),
                'data_fim' => $plano->data_fim?->toDateString(),
                'estado' => (int) $plano->estado,
                'updated_at' => $plano->updated_at?->toDateTimeString(),
                'alvos' => $alvos,
            ],
            // Fixo a 0 de propósito: com uma propina de outro ano a pré-visualização RECUSA (recusarPropinasDeOutrosAnos)
            // antes de chegar aqui, logo uma impressão só existe quando a contagem é 0. Se um dia a recusa passar a
            // aviso, esta contagem real tem de entrar na impressão (D8).
            'propinas_de_outros_anos' => 0,
            'reassociadas_sem_ajuste' => array_map('intval', $reassociadasSemAjuste),
            'propinas' => $candidatas->sortBy('id')->map(fn (Propina $p) => [
                (int) $p->id,
                (int) $p->matricula_id,
                (int) $p->plano_propina_id,
                (int) $p->ano_lectivo_id,
                (int) $p->ordem,
                $p->periodo_inicio->toDateString(),
                $p->estado->value,
                $p->valor->unidadesMenores(),
                $p->valor_pago->unidadesMenores(),
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function rotuloDoMes(string $dia): string
    {
        return RotuloDePeriodo::competencia((int) substr($dia, 0, 4), (int) substr($dia, 5, 2));
    }
}
