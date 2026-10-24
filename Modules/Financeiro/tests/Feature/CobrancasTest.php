<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AnularPagamentoAction;
use Modules\Financeiro\Actions\CancelarCobrancaAction;
use Modules\Financeiro\Actions\CriarCobrancaAction;
use Modules\Financeiro\Actions\PagarCobrancaAction;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\CatalogoAlvo;
use Modules\Financeiro\Enums\OrigemCobranca;
use Modules\Financeiro\Enums\MomentoObrigatorio;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\ProdutoVariante;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Services\GerarCobrancasObrigatorias;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class CobrancasTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function produto(array $extra = []): Produto
    {
        return Produto::create($extra + ['nome' => 'Camisola', 'codigo' => 'UNI-1', 'preco' => Dinheiro::deUnidadesMenores(500_000)]);
    }

    private function servico(): Servico
    {
        return Servico::create(['nome' => 'Declaração', 'codigo' => 'SER-1', 'preco' => Dinheiro::deUnidadesMenores(300_000)]);
    }

    private function criar($matricula, array $itens)
    {
        return app(CriarCobrancaAction::class)->executar($matricula, $itens, null, null, utilizadorId: $this->adminEscola()->id);
    }

    private function recusa(string $campo, callable $operacao): void
    {
        try {
            $operacao();
            $this->fail("Devia recusar em «{$campo}».");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());
        }
    }

    public function test_cria_cobranca_com_copia_do_preco_e_abate_stock(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $produto = $this->produto(['controla_stock' => true, 'stock_quantidade' => 5]);
        $servico = $this->servico();

        $cobranca = $this->criar($matricula, [
            ['tipo' => 'produto', 'id' => $produto->id, 'quantidade' => 2],
            ['tipo' => 'servico', 'id' => $servico->id, 'quantidade' => 1],
        ]);

        $this->assertSame(1_300_000, $cobranca->total->unidadesMenores());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $cobranca->estado);
        $this->assertCount(2, $cobranca->itens);
        $this->assertSame(3, $produto->fresh()->stock_quantidade);

        $produto->update(['preco' => Dinheiro::deUnidadesMenores(900_000)]);
        $this->assertSame(500_000, $cobranca->fresh('itens')->itens[0]->preco_unitario->unidadesMenores(), 'O preço da venda não muda com o catálogo.');
    }

    public function test_stock_insuficiente_e_tamanho_obrigatorio(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $semVariantes = $this->produto(['controla_stock' => true, 'stock_quantidade' => 1]);
        $comVariantes = $this->produto(['nome' => 'Bata', 'codigo' => 'UNI-2', 'tem_variantes' => true, 'controla_stock' => true]);
        $m = ProdutoVariante::create(['produto_id' => $comVariantes->id, 'nome' => 'M', 'stock_quantidade' => 1]);

        $this->recusa('itens.0.quantidade', fn () => $this->criar($matricula, [['tipo' => 'produto', 'id' => $semVariantes->id, 'quantidade' => 2]]));
        $this->recusa('itens.0.variante_id', fn () => $this->criar($matricula, [['tipo' => 'produto', 'id' => $comVariantes->id, 'quantidade' => 1]]));

        $this->criar($matricula, [['tipo' => 'produto', 'id' => $comVariantes->id, 'variante_id' => $m->id, 'quantidade' => 1]]);
        $this->assertSame(0, $m->fresh()->stock_quantidade);
        $this->recusa('itens.0.quantidade', fn () => $this->criar($matricula, [['tipo' => 'produto', 'id' => $comVariantes->id, 'variante_id' => $m->id, 'quantidade' => 1]]));
    }

    public function test_servico_exige_situacao_regularizada_conforme_a_regra(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 1); // dívida de período já iniciado
        $servico = $this->servico();
        $produto = $this->produto();

        $this->recusa('itens', fn () => $this->criar($matricula, [['tipo' => 'servico', 'id' => $servico->id, 'quantidade' => 1]]));
        $this->assertNotNull($this->criar($matricula, [['tipo' => 'produto', 'id' => $produto->id, 'quantidade' => 1]]), 'Produtos não exigem a regra.');

        RegraCobranca::doTenant()->update(['exigir_regularizacao_servicos' => false]);
        $this->assertNotNull($this->criar($matricula, [['tipo' => 'servico', 'id' => $servico->id, 'quantidade' => 1]]));
    }

    public function test_paga_anula_o_pagamento_e_cancela_repondo_o_stock(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $produto = $this->produto(['controla_stock' => true, 'stock_quantidade' => 5]);
        $cobranca = $this->criar($matricula, [['tipo' => 'produto', 'id' => $produto->id, 'quantidade' => 2]]);

        $pagamento = app(PagarCobrancaAction::class)->executar($cobranca, $this->pedidoPagamento(1_000_000));
        $cobranca = $cobranca->fresh();

        $this->assertSame(EstadoCobranca::PAGA, $cobranca->estado);
        $this->assertSame(1_000_000, $pagamento->valor->unidadesMenores());
        $this->assertSame(1_000_000, $cobranca->valor_pago->unidadesMenores());
        $this->recusa('cobranca', fn () => app(CancelarCobrancaAction::class)->executar($cobranca, 'Engano de lançamento', null));

        app(AnularPagamentoAction::class)->executar($pagamento, new MotivoDeAnulacaoDTO('Pagamento lançado por engano'), $this->adminEscola()->id);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $cobranca->fresh()->estado);

        app(CancelarCobrancaAction::class)->executar($cobranca->fresh(), 'Cliente desistiu da compra', $this->adminEscola()->id);
        $this->assertSame(EstadoCobranca::CANCELADA, $cobranca->fresh()->estado);
        $this->assertSame(5, $produto->fresh()->stock_quantidade, 'O stock volta ao que era.');
    }

    public function test_criar_e_pagar_pelo_http_e_recibo_com_itens(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $servico = $this->servico();
        $metodo = $this->metodoPagamento();
        $admin = $this->adminEscola();

        $this->actingAs($admin)->post('/financeiro/cobrancas', [
            'matricula_id' => $matricula->id,
            'itens' => [['tipo' => 'servico', 'id' => $servico->id, 'quantidade' => 2]],
            'pagar_agora' => true,
            'metodo_pagamento_id' => $metodo->id,
            'data_pagamento' => '2026-10-16',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $cobranca = Cobranca::query()->firstOrFail();
        $this->assertSame(EstadoCobranca::PAGA, $cobranca->estado);

        $pagamento = Pagamento::query()->firstOrFail();
        $this->actingAs($admin)->get("/financeiro/pagamentos/{$pagamento->id}/recibo")
            ->assertOk()->assertInertia(fn ($page) => $page->where('recibo.itens.0.valor', 600_000)->where('recibo.total_liquidado', 600_000));
        $this->actingAs($admin)->get('/financeiro/cobrancas')->assertOk();
        $this->actingAs($admin)->get("/financeiro/cobrancas/{$cobranca->id}")->assertOk();
    }

    public function test_gera_os_obrigatorios_uma_so_vez_e_respeita_alvos_e_regra(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $cartao = $this->produto(['nome' => 'Cartão', 'codigo' => 'CAR', 'obrigatorio' => true, 'momento_obrigatorio' => MomentoObrigatorio::UMA_SO_VEZ]);
        $this->servico()->update(['obrigatorio' => true, 'momento_obrigatorio' => MomentoObrigatorio::AO_MATRICULAR]);
        $outroNivel = $this->nivel('OUTRO');
        $so9 = $this->produto(['nome' => 'Só 9.ª', 'codigo' => 'S9', 'obrigatorio' => true, 'momento_obrigatorio' => MomentoObrigatorio::AO_MATRICULAR]);
        CatalogoAlvo::create(['item_tipo' => 'produto', 'item_id' => $so9->id, 'nivel_academico_id' => $outroNivel->id]);
        $gerar = app(GerarCobrancasObrigatorias::class);

        $cobranca = $gerar->executar($matricula->id);

        $this->assertNotNull($cobranca);
        $this->assertSame(OrigemCobranca::OBRIGATORIO, $cobranca->origem);
        $this->assertEqualsCanonicalizing(['Cartão', 'Declaração'], $cobranca->itens->pluck('nome')->all(), 'O item de outro nível fica de fora.');
        $this->assertNull($gerar->executar($matricula->id), 'Idempotente: não repete.');

        RegraCobranca::doTenant()->update(['gerar_obrigatorios_ao_matricular' => false]);
        $this->assertNull($gerar->executar($matricula->id));
        $this->assertNotNull($cartao->fresh());
    }
}
