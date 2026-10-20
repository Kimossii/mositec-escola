<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

/**
 * «Pagar até [mês]» e a geração por mês no pagamento (spec Financeiro):
 * gera as propinas em falta até ao mês pedido, aplica as multas devidas e distribui o dinheiro numa só transacção.
 */
class PagamentoAteMesTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Set–Dez pagos; Jan e Fev em dívida; nada de Mar em diante. */
    private function cenarioComJanEFevEmDivida(): array
    {
        Carbon::setTestNow(CarbonImmutable::parse('2027-02-15 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->regras(antecipado: true);

        foreach (range(1, 4) as $ordem) {
            $this->comEstado($this->propina($matricula, $plano, $ordem), EstadoCobranca::PAGA);
        }

        return ['plano' => $plano, 'matricula' => $matricula, 'jan' => $this->propina($matricula, $plano, 5), 'fev' => $this->propina($matricula, $plano, 6)];
    }

    public function test_pagar_ate_abril_gera_e_cobra_jan_a_abr_em_ordem(): void
    {
        ['plano' => $plano, 'matricula' => $matricula, 'jan' => $jan, 'fev' => $fev] = $this->cenarioComJanEFevEmDivida();

        $pagamento = $this->pagar($matricula, 10_000_000, null, 0, '2027-02-15', null, 2027, 4);

        // Jan–Abr existem e estão todas pagas; Mai e Jun não foram geradas.
        $this->assertSame(8, Propina::query()->count());
        $periodos = ['2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01'];

        $this->assertSame($periodos, Propina::query()->whereIn('periodo_inicio', $periodos)->orderBy('periodo_inicio')->get()->pluck('periodo_inicio')->map(fn ($p) => $p->toDateString())->all());
        $this->assertSame(4, Propina::query()->whereIn('periodo_inicio', $periodos)->where('estado', EstadoCobranca::PAGA->value)->count());
        $this->assertSame(0, Propina::query()->whereIn('periodo_inicio', ['2027-05-01', '2027-06-01'])->count());

        // Distribuição na ordem Jan → Fev → Mar → Abr.
        $pagamento->load('distribuicoes.propina');
        $this->assertSame($periodos, $pagamento->distribuicoes->map(fn ($d) => $d->propina->periodo_inicio->toDateString())->all());
    }

    public function test_repetir_o_pedido_nao_duplica_propinas(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioComJanEFevEmDivida();

        $this->pagar($matricula, 10_000_000, null, 0, '2027-02-15', null, 2027, 4);
        $this->assertSame(8, Propina::query()->count());

        // Geração idempotente: repetir o mesmo pedido não cria propinas novas.
        $resultado = DB::transaction(fn () => app(GerarPropinasService::class)->gerarParaMatriculaNaTransaccao(
            $matricula->fresh(),
            new PedidoGeracaoPropinasDTO(
                ano_lectivo_id: (int) $matricula->ano_lectivo_id,
                ambito: AmbitoGeracaoPropinas::MATRICULA,
                origem: OrigemGeracaoPropina::MANUAL,
                matricula_numero: $matricula->numero_registo_matricula,
                ate_ano: 2027,
                ate_mes: 4,
            ),
        ));

        $this->assertSame(0, $resultado->contagens()['criadas']);
        $this->assertSame(8, Propina::query()->count());
    }

    public function test_propina_em_atraso_mostra_multa_na_previa_e_no_pagamento_sem_o_cron(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->regras(antecipado: false);
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]); // 5%, L1 = 16/09; Set está em atraso
        $set = $this->propina($matricula, $plano, 1);

        $dto = $this->pedidoPagamento(2_625_000, null, 0, '2026-10-16');

        // Pré-visualização: mostra a multa de hoje sem gravar.
        $previa = app(DistribuicaoDePagamentoService::class)->preVisualizarPagamento($matricula->fresh(), $dto);
        $this->assertSame(125_000, $previa->linhas[0]['multa_pendente_antes']);
        $this->assertSame(125_000, $previa->linhas[0]['multa_dinheiro']);
        $this->assertSame(2_625_000, $previa->totalDistribuido());
        $this->assertSame(0, PropinaMulta::count(), 'A pré-visualização não grava a multa.');

        // Pagamento: nasce a multa e paga capital + multa.
        $pagamento = $this->pagar($matricula, 2_625_000, null, 0, '2026-10-16');
        $this->assertSame(125_000, PropinaMulta::sole()->valor->unidadesMenores());
        $this->assertSame(2_500_000, $set->fresh()->valor_pago->unidadesMenores());

        $pagamento->load('multas');
        $total = $pagamento->distribuicoes->sum(fn ($d) => $d->valor->unidadesMenores()) + $pagamento->multas->sum(fn ($d) => $d->valor->unidadesMenores());
        $this->assertSame(2_625_000, $total, 'O pagamento distribui capital e multa.');
        $this->assertPagamentosCoerentes();
    }

    public function test_com_multa_activa_desligada_nao_nasce_multa_nova_e_a_existente_mantem_se(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->regras(antecipado: false);
        $this->multaActiva([[1, TipoMulta::PERCENTAGEM, 500]]);
        $set = $this->propina($matricula, $plano, 1);
        $existente = $this->multa($set, 50_000);
        RegraCobranca::doTenant()->update(['multa_activa' => false]);

        $this->pagar($matricula, 2_550_000, null, 0, '2026-10-16');

        $this->assertSame(1, PropinaMulta::count(), 'Nenhuma multa nova.');
        $this->assertSame(50_000, $existente->fresh()->valor->unidadesMenores(), 'A multa existente mantém-se.');
    }

    public function test_pagar_ate_um_mes_futuro_sem_antecipado_e_recusado(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $this->regras(antecipado: false); // antecipado desligado

        try {
            $this->pagar($matricula, 2_500_000, null, 0, '2026-10-16', null, 2027, 4);
            $this->fail('Devia recusar «Pagar até» um mês futuro sem pagamento antecipado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ate_mes', $e->errors());
            $this->assertStringContainsString('não permite pagamento antecipado', $e->errors()['ate_mes'][0]);
        }
    }

    public function test_a_previa_e_o_pagamento_dao_o_mesmo_total(): void
    {
        ['matricula' => $matricula] = $this->cenarioComJanEFevEmDivida();
        $dto = $this->pedidoPagamento(10_000_000, null, 0, '2027-02-15', null, null, 2027, 4);

        $previa = app(DistribuicaoDePagamentoService::class)->preVisualizarPagamento($matricula->fresh(), $dto);
        $pagamento = $this->pagar($matricula, 10_000_000, null, 0, '2027-02-15', null, 2027, 4);
        $pagamento->load('multas');

        $total = $pagamento->distribuicoes->sum(fn ($d) => $d->valor->unidadesMenores()) + $pagamento->multas->sum(fn ($d) => $d->valor->unidadesMenores());

        $this->assertSame($previa->totalDistribuido(), $total);
        $this->assertSame(4, count($previa->linhas));
    }
}
