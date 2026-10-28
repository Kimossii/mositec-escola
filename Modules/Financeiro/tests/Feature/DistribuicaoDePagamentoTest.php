<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Financeiro\DTO\PlanoDePagamentoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use Tests\TestCase;

/**
 * Hoje = 16/10/2026. Plano mensal Set→Jun a 25.000,00. Set e Out já começaram; Nov em diante são futuras.
 */
class DistribuicaoDePagamentoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    /** @var array<string, Propina> */
    private array $p;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->p = ['set' => $this->propina($this->matricula, $plano, 1), 'out' => $this->propina($this->matricula, $plano, 2), 'nov' => $this->propina($this->matricula, $plano, 3)];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function previa(int $valor, ?array $linhas = null, int $credito = 0, string $data = '2026-10-16'): PlanoDePagamentoDTO
    {
        return app(DistribuicaoDePagamentoService::class)->preVisualizarPagamento($this->matricula, $this->pedidoPagamento($valor, $linhas, $credito, $data));
    }

    /** @return array<int, array{0: int, 1: int}> propina_id => [crédito, dinheiro] */
    private function porPropina(PlanoDePagamentoDTO $plano): array
    {
        $saida = [];

        foreach ($plano->linhas as $linha) {
            $saida[$linha['propina_id']] = [$linha['credito'], $linha['dinheiro']];
        }

        return $saida;
    }

    private function assertRecusado(string $campo, Closure $operacao): void
    {
        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
        }
    }

    public function test_explicita_paga_o_saldo_e_o_resto_e_excedente(): void
    {
        $this->regras();
        $plano = $this->previa(3_000_000, [[$this->p['set'], 2_500_000]]);

        $this->assertSame([$this->p['set']->id => [0, 2_500_000]], $this->porPropina($plano));
        $this->assertSame(500_000, $plano->excedente);
        $this->assertSame([['propina_id' => $this->p['set']->id, 'valor' => 2_500_000]], $plano->distribuicoes());
    }

    public function test_sem_pagamento_parcial_a_linha_tem_de_ser_o_saldo_e_com_parcial_pode_ser_menos(): void
    {
        $this->regras(parcial: false);
        $this->assertRecusado('distribuicao.0.valor', fn () => $this->previa(1_000_000, [[$this->p['set'], 1_000_000]]));

        $this->regras(parcial: true);
        $this->assertSame([$this->p['set']->id => [0, 1_000_000]], $this->porPropina($this->previa(1_000_000, [[$this->p['set'], 1_000_000]])));
    }

    public function test_pagar_o_remanescente_de_uma_parcial_e_sempre_permitido(): void
    {
        $this->regras(parcial: false);
        $this->comEstado($this->p['set'], EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);

        $plano = $this->previa(1_500_000, [[$this->p['set'], 1_500_000]]);

        $this->assertSame([$this->p['set']->id => [0, 1_500_000]], $this->porPropina($plano));
        $this->assertSame(0, $plano->linhas[0]['saldo_depois']);
    }

    public function test_linha_maior_que_o_saldo_ou_soma_maior_que_o_valor_recusa(): void
    {
        $this->regras(parcial: true);
        $this->assertRecusado('distribuicao.0.valor', fn () => $this->previa(3_000_000, [[$this->p['set'], 2_600_000]]));
        $this->assertRecusado('distribuicao', fn () => $this->previa(3_000_000, [[$this->p['set'], 2_500_000], [$this->p['out'], 1_000_000]]));
    }

    public function test_antecipado_depende_da_regra_e_da_data_do_pagamento(): void
    {
        $this->regras(antecipado: false);
        $this->assertRecusado('distribuicao.0.propina_id', fn () => $this->previa(2_500_000, [[$this->p['nov'], 2_500_000]]));

        $this->regras(antecipado: true);
        $tres = [[$this->p['set'], 2_500_000], [$this->p['out'], 2_500_000], [$this->p['nov'], 2_500_000]];
        $this->assertSame(
            [$this->p['set']->id => [0, 2_500_000], $this->p['out']->id => [0, 2_500_000], $this->p['nov']->id => [0, 2_500_000]],
            $this->porPropina($this->previa(7_500_000, $tres)),
        );

        $this->regras(antecipado: false);
        $this->assertRecusado('distribuicao.0.propina_id', fn () => $this->previa(2_500_000, [[$this->p['out'], 2_500_000]], data: '2026-09-30'));
    }

    public function test_explicita_nao_salta_meses_em_divida(): void
    {
        $this->regras(parcial: true, antecipado: true);

        // Novembro sem Setembro/Outubro: recusado na linha de Novembro, a apontar o mês mais antigo em falta.
        try {
            $this->previa(2_500_000, [[$this->p['nov'], 2_500_000]]);
            $this->fail('Devia recusar o salto de meses.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('distribuicao.0.propina_id', $e->errors());
            $this->assertStringContainsString('09/2026', $e->errors()['distribuicao.0.propina_id'][0]);
        }

        // Outubro sem Setembro: idem.
        $this->assertRecusado('distribuicao.0.propina_id', fn () => $this->previa(2_500_000, [[$this->p['out'], 2_500_000]]));

        // Pagar parte do mais antigo e o mês seguinte não é salto (cada mês recebe algo); pelo meio, o salto é recusado.
        $plano = $this->previa(3_000_000, [[$this->p['set'], 1_000_000], [$this->p['out'], 2_000_000]]);
        $this->assertSame([$this->p['set']->id => [0, 1_000_000], $this->p['out']->id => [0, 2_000_000]], $this->porPropina($plano));
        $this->assertRecusado('distribuicao.1.propina_id', fn () => $this->previa(3_000_000, [[$this->p['set'], 1_000_000], [$this->p['nov'], 2_000_000]]));
    }

    public function test_propina_de_outra_matricula_paga_cancelada_ou_anulada_nao_e_pagavel(): void
    {
        $this->regras();
        ['plano' => $outroPlano, 'matricula' => $outra] = $this->cenarioPropinasNoMesmoAno();
        $alheia = $this->propina($outra, $outroPlano, 1);
        $paga = $this->comEstado($this->propina($this->matricula, $this->p['set']->plano, 4), EstadoCobranca::PAGA);
        $cancelada = $this->comEstado($this->propina($this->matricula, $this->p['set']->plano, 5), EstadoCobranca::CANCELADA);
        $anulada = $this->comEstado($this->propina($this->matricula, $this->p['set']->plano, 6), EstadoCobranca::ANULADA);

        foreach ([$alheia, $paga, $cancelada, $anulada] as $propina) {
            $this->assertRecusado('distribuicao.0.propina_id', fn () => $this->previa(2_500_000, [[$propina, 2_500_000]]));
        }
    }

    /**
     * O filtro do próprio `calcular()` é a ÚNICA revalidação do estado depois do lock (a Action filtra por estado antes de
     * bloquear): uma propina cancelada/anulada por outra sessão nesse intervalo tem de ser recusada ou ignorada aqui.
     */
    public function test_calcular_directo_recusa_ou_ignora_propinas_canceladas_e_anuladas(): void
    {
        $this->regras(antecipado: true);
        $cancelada = $this->comEstado($this->propina($this->matricula, $this->p['set']->plano, 4), EstadoCobranca::CANCELADA);
        $anulada = $this->comEstado($this->propina($this->matricula, $this->p['set']->plano, 5), EstadoCobranca::ANULADA);
        $this->assertGreaterThan(0, $cancelada->saldo()->unidadesMenores(), 'Preparação: a cancelada tem saldo.');
        $this->assertGreaterThan(0, $anulada->saldo()->unidadesMenores(), 'Preparação: a anulada tem saldo.');

        $servico = app(DistribuicaoDePagamentoService::class);
        $colecao = new Collection([$this->p['set'], $cancelada, $anulada]);
        $calcular = fn (int $valor, ?array $linhas) => $servico->calcular(
            $valor, 0, CarbonImmutable::parse('2026-10-16'), $linhas === null ? null : $this->linhas($linhas),
            $colecao, new Collection(), RegraCobranca::doTenant(),
        );

        // Explícito: a linha 1 (cancelada, depois anulada) é recusada no índice certo.
        $this->assertRecusado('distribuicao.1.propina_id', fn () => $calcular(5_000_000, [[$this->p['set'], 2_500_000], [$cancelada, 2_500_000]]));
        $this->assertRecusado('distribuicao.1.propina_id', fn () => $calcular(5_000_000, [[$this->p['set'], 2_500_000], [$anulada, 2_500_000]]));

        // Automático: ignoradas (mesmo com dinheiro de sobra e antecipado permitido); o resto é excedente.
        $plano = $calcular(10_000_000, null);
        $this->assertSame([$this->p['set']->id], array_column($plano->linhas, 'propina_id'));
        $this->assertSame(7_500_000, $plano->excedente);
    }

    public function test_ids_repetidos_sao_recusados_no_dto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pedidoPagamento(5_000_000, [[$this->p['set'], 2_500_000], [$this->p['set'], 2_500_000]]);
    }

    public function test_automatica_paga_da_mais_antiga_para_a_mais_recente_e_para_nas_futuras_sem_antecipado(): void
    {
        $this->regras(parcial: false, antecipado: false);
        $plano = $this->previa(5_000_000);

        $this->assertSame([$this->p['set']->id => [0, 2_500_000], $this->p['out']->id => [0, 2_500_000]], $this->porPropina($plano));
        $this->assertSame(0, $plano->excedente);

        try {
            $this->previa(8_000_000);
            $this->fail('Sem antecipado, o excedente sobre a dívida é recusado.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('valor', $e->errors());
        }

        $this->regras(parcial: false, antecipado: true);
        $this->assertSame(500_000, $this->previa(8_000_000)->excedente, 'Com antecipado paga também Novembro.');
    }

    public function test_automatica_sem_parcial_para_na_primeira_que_nao_cabe_e_com_parcial_paga_parte(): void
    {
        $this->regras(parcial: false);
        $plano = $this->previa(3_000_000);
        $this->assertSame([$this->p['set']->id => [0, 2_500_000]], $this->porPropina($plano));
        $this->assertSame(500_000, $plano->excedente, 'Nunca salta Outubro; o resto vai a crédito.');

        $this->regras(parcial: true);
        $plano = $this->previa(3_000_000);
        $this->assertSame([$this->p['set']->id => [0, 2_500_000], $this->p['out']->id => [0, 500_000]], $this->porPropina($plano));
        $this->assertSame(0, $plano->excedente);
    }

    public function test_sem_divida_tudo_e_excedente(): void
    {
        $this->regras();
        foreach ($this->p as $propina) {
            $this->comEstado($propina, EstadoCobranca::PAGA);
        }

        $plano = $this->previa(1_000_000);

        $this->assertSame([], $plano->linhas);
        $this->assertSame(1_000_000, $plano->excedente);
    }

    public function test_credito_e_usado_primeiro_do_mais_antigo_e_pode_cobrir_uma_linha_com_dois_creditos(): void
    {
        $this->regras(parcial: true);
        $antigo = $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 1_000_000), 1_000_000);
        $novo = $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 2_000_000), 2_000_000);

        $plano = $this->previa(2_000_000, null, 3_000_000); // 5.000,00 para Set e Out

        $this->assertSame([$this->p['set']->id => [2_500_000, 0], $this->p['out']->id => [500_000, 2_000_000]], $this->porPropina($plano));
        $this->assertSame([
            ['credito_id' => $antigo->id, 'propina_id' => $this->p['set']->id, 'propina_multa_id' => null, 'valor' => 1_000_000],
            ['credito_id' => $novo->id, 'propina_id' => $this->p['set']->id, 'propina_multa_id' => null, 'valor' => 1_500_000],
            ['credito_id' => $novo->id, 'propina_id' => $this->p['out']->id, 'propina_multa_id' => null, 'valor' => 500_000],
        ], $plano->utilizacoes);
        $this->assertSame(0, $plano->excedente);
    }

    public function test_credito_pedido_acima_do_disponivel_ou_do_distribuido_recusa(): void
    {
        $this->regras(parcial: true);
        $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 1_000_000), 1_000_000);

        $this->assertRecusado('valor_credito', fn () => $this->previa(1_000_000, null, 1_500_000));
        $this->assertRecusado('valor_credito', fn () => $this->previa(1_000_000, [[$this->p['set'], 500_000]], 1_000_000));
    }

    public function test_creditos_de_outro_aluno_nao_contam(): void
    {
        $this->regras(parcial: true);
        ['matricula' => $outra] = $this->cenarioPropinasNoMesmoAno();
        $this->creditoDirecto($this->pagamentoDirecto($outra, 1_000_000), 1_000_000);

        $this->assertRecusado('valor_credito', fn () => $this->previa(1_000_000, null, 500_000));
    }

    public function test_data_futura_recusa_e_a_previsualizacao_nao_escreve(): void
    {
        $this->regras();
        $this->assertRecusado('data_pagamento', fn () => $this->previa(2_500_000, null, 0, '2026-10-17'));

        $antes = [Pagamento::query()->count(), PagamentoPropina::query()->count(), CreditoUtilizacao::query()->count()];
        $this->previa(8_000_000);
        $this->assertSame($antes, [Pagamento::query()->count(), PagamentoPropina::query()->count(), CreditoUtilizacao::query()->count()]);
    }

    public function test_data_nunca_anterior_a_matricula(): void
    {
        $this->regras();
        $this->assertSame('2026-09-01', $this->matricula->data_matricula->toDateString());

        $this->assertSame(2_500_000, $this->previa(2_500_000, null, 0, '2026-09-01')->totalDistribuido(), 'Igual ao limite passa.');

        try {
            $this->previa(2_500_000, null, 0, '2026-08-31');
            $this->fail('Um dia antes da matrícula devia recusar.');
        } catch (ValidationException $e) {
            $this->assertSame(['A data do pagamento não pode ser anterior à data da matrícula (01/09/2026).'], $e->errors()['data_pagamento']);
        }

        $this->assertRecusado('data_pagamento', fn () => $this->previa(2_500_000, null, 0, '2026-10-17'));
    }

    /**
     * Matrícula de outro aluno cujas propinas foram criadas por ordem INVERSA: Outubro tem id MENOR que Setembro.
     *
     * @return array{matricula: Matricula, set: Propina, out: Propina}
     */
    private function matriculaComIdsInvertidos(): array
    {
        ['plano' => $plano, 'matricula' => $outra] = $this->cenarioPropinasNoMesmoAno();
        $out = $this->propina($outra, $plano, 2);
        $set = $this->propina($outra, $plano, 1);
        $this->assertGreaterThan($out->id, $set->id, 'Preparação: a propina mais antiga tem o id maior.');

        return ['matricula' => $outra, 'set' => $set, 'out' => $out];
    }

    public function test_automatica_ordena_por_periodo_e_nao_por_id(): void
    {
        $this->regras(parcial: false);
        ['matricula' => $outra, 'set' => $set, 'out' => $out] = $this->matriculaComIdsInvertidos();
        $servico = app(DistribuicaoDePagamentoService::class);

        $so_uma = $servico->preVisualizarPagamento($outra, $this->pedidoPagamento(2_500_000));
        $this->assertSame([$set->id => [0, 2_500_000]], $this->porPropina($so_uma), 'Com dinheiro para uma só, paga Setembro (a mais antiga), mesmo com id maior.');

        $duas = $servico->preVisualizarPagamento($outra, $this->pedidoPagamento(5_000_000));
        $this->assertSame([$set->id, $out->id], array_column($duas->linhas, 'propina_id'), 'As linhas seguem o período, não o id.');
    }

    public function test_explicita_poe_o_credito_na_propina_mais_antiga_e_nao_na_primeira_linha_nem_no_menor_id(): void
    {
        $this->regras(parcial: false);
        ['matricula' => $outra, 'set' => $set, 'out' => $out] = $this->matriculaComIdsInvertidos();
        $this->creditoDirecto($this->pagamentoDirecto($outra, 2_500_000), 2_500_000);

        // O pedido lista Outubro primeiro; o crédito (2.500,00) tem de cobrir Setembro, a mais antiga.
        $plano = app(DistribuicaoDePagamentoService::class)->preVisualizarPagamento(
            $outra,
            $this->pedidoPagamento(2_500_000, [[$out, 2_500_000], [$set, 2_500_000]], 2_500_000),
        );

        $this->assertSame([$set->id => [2_500_000, 0], $out->id => [0, 2_500_000]], $this->porPropina($plano));
        $this->assertSame([$set->id, $out->id], array_column($plano->linhas, 'propina_id'));
        $this->assertSame([$set->id], array_column($plano->utilizacoes, 'propina_id'));
    }

    public function test_so_creditos_activos_contam(): void
    {
        $this->regras(parcial: true);
        $credito = $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 1_000_000), 1_000_000);
        app(RecalcularCredito::class)->anular($credito);
        $anulado = $credito->fresh();

        $this->assertSame(EstadoCredito::ANULADO, $anulado->estado);
        $this->assertGreaterThan(0, $anulado->saldo()->unidadesMenores(), 'Preparação: o anulado tem saldo.');

        $servico = app(DistribuicaoDePagamentoService::class);
        $this->assertSame([], $servico->creditosDisponiveis($this->matricula)->all());
        $this->assertRecusado('valor_credito', fn () => $this->previa(1_000_000, null, 500_000));

        // O filtro do próprio motor: mesmo que alguém lhe passe o anulado, não o usa.
        $this->assertRecusado('valor_credito', fn () => $servico->calcular(
            1_000_000, 500_000, CarbonImmutable::parse('2026-10-16'), null,
            $servico->propinasPagaveis($this->matricula), new Collection([$anulado]), RegraCobranca::doTenant(),
        ));
    }

    public function test_previsualizar_credito_explicita_com_soma_igual_ao_credito(): void
    {
        $this->regras();
        $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 2_500_000), 2_500_000);

        $plano = app(DistribuicaoDePagamentoService::class)->preVisualizarCredito($this->matricula, $this->pedidoCredito(2_500_000, [[$this->p['set'], 2_500_000]]));

        $this->assertSame([$this->p['set']->id => [2_500_000, 0]], $this->porPropina($plano));
        $this->assertSame(0, $plano->dinheiro);
        $this->assertSame(0, $plano->excedente);
        $this->assertSame(2_500_000, array_sum(array_column($plano->utilizacoes, 'valor')));
    }

    public function test_previsualizar_credito_automatica_distribui_o_credito_pelas_mais_antigas(): void
    {
        $this->regras(parcial: true);
        $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 3_000_000), 3_000_000);

        $plano = app(DistribuicaoDePagamentoService::class)->preVisualizarCredito($this->matricula, $this->pedidoCredito(3_000_000));

        $this->assertSame([$this->p['set']->id => [2_500_000, 0], $this->p['out']->id => [500_000, 0]], $this->porPropina($plano));
    }

    public function test_previsualizar_credito_acima_do_distribuido_ou_do_disponivel_recusa(): void
    {
        $this->regras(parcial: true);
        $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 3_000_000), 3_000_000);
        $servico = app(DistribuicaoDePagamentoService::class);

        $this->assertRecusado('valor_credito', fn () => $servico->preVisualizarCredito($this->matricula, $this->pedidoCredito(3_000_000, [[$this->p['set'], 2_500_000]])));
        $this->assertRecusado('valor_credito', fn () => $servico->preVisualizarCredito($this->matricula, $this->pedidoCredito(3_000_001)));
    }

    public function test_previsualizar_credito_usa_a_data_de_hoje_da_escola_o7(): void
    {
        $this->regras(antecipado: false);
        $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 7_500_000), 7_500_000);
        $servico = app(DistribuicaoDePagamentoService::class);
        $pedido = $this->pedidoCredito(7_500_000, [[$this->p['set'], 2_500_000], [$this->p['out'], 2_500_000], [$this->p['nov'], 2_500_000]]);

        // Hoje = 16/10: Novembro ainda não começou (sem antecipado).
        $this->assertRecusado('distribuicao.2.propina_id', fn () => $servico->preVisualizarCredito($this->matricula, $pedido));

        // Avançar o relógio da escola para 02/11: já começou, a mesma linha passa.
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));
        $plano = $servico->preVisualizarCredito($this->matricula, $pedido);
        $this->assertSame([$this->p['set']->id => [2_500_000, 0], $this->p['out']->id => [2_500_000, 0], $this->p['nov']->id => [2_500_000, 0]], $this->porPropina($plano));
    }

    /** @return array{plano: mixed, matricula: Matricula} outra matrícula (outro aluno) noutro plano do mesmo ano */
    private function cenarioPropinasNoMesmoAno(): array
    {
        $plano = $this->p['set']->plano;
        $turma = $this->turma($plano->anoLectivo, $this->nivel('N' . uniqid()));

        return ['plano' => $plano, 'matricula' => $this->matriculaActiva($turma)];
    }
}
