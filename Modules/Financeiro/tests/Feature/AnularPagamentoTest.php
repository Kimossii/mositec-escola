<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class AnularPagamentoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    /** @var array<string, Propina> */
    private array $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->p = ['set' => $this->propina($this->matricula, $plano, 1), 'out' => $this->propina($this->matricula, $plano, 2)];
        $this->actingAs($this->adminEscola());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function contagens(): array
    {
        return [Pagamento::query()->count(), PagamentoPropina::query()->count(), Credito::query()->count(), CreditoUtilizacao::query()->count()];
    }

    private function assertRecusado(string $campo, string $texto, Closure $operacao): void
    {
        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
            $this->assertStringContainsString($texto, $e->errors()[$campo][0]);
        }
    }

    public function test_anula_reverte_as_propinas_e_o_credito_e_nada_se_apaga(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 6_000_000, data: '2026-10-02'); // Set + Out + 1.000.000 de crédito
        $antes = $this->contagens();

        $anulado = $this->anularPagamento($pagamento, '  Valor errado no talão  ');

        $this->assertSame(EstadoPagamento::ANULADO, $anulado->estado);
        $this->assertSame('Valor errado no talão', $anulado->motivo_anulacao);
        $this->assertSame(auth()->id(), (int) $anulado->anulado_por);
        $this->assertNotNull($anulado->anulado_em);
        $this->assertSame($pagamento->numero, $anulado->numero);
        $this->assertSame(EstadoCredito::ANULADO, $anulado->credito->estado);

        foreach (['set', 'out'] as $mes) {
            $propina = $this->p[$mes]->fresh();
            $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->estado, $mes);
            $this->assertNull($propina->capital_liquidado_em, $mes);
        }

        $this->assertSame($antes, $this->contagens(), 'Nenhuma linha eliminada.');
        $this->assertPagamentosCoerentes();
    }

    public function test_anular_duas_vezes_recusa(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]]);
        $this->anularPagamento($pagamento);

        $this->assertRecusado('pagamento', 'já está anulado', fn () => $this->anularPagamento($pagamento));
    }

    public function test_bloqueado_enquanto_o_credito_gerado_tem_utilizacoes_activas(): void
    {
        $this->regras(parcial: true);
        $origem = $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]); // 500.000 de crédito
        $utilizacao = $this->aplicarCredito($this->p['out'], 500_000);

        $this->assertRecusado('pagamento', 'anule primeiro', fn () => $this->anularPagamento($origem));

        $this->anularUtilizacao($utilizacao);
        $this->assertSame(EstadoCredito::DISPONIVEL, $origem->credito->fresh()->estado);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p['out']->fresh()->estado);

        $this->anularPagamento($origem);
        $this->assertSame(EstadoCredito::ANULADO, $origem->credito->fresh()->estado);
        $this->assertPagamentosCoerentes();
    }

    public function test_anular_utilizacao_duas_vezes_recusa(): void
    {
        $this->regras(parcial: true);
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]);
        $utilizacao = $this->aplicarCredito($this->p['out'], 500_000);
        $this->anularUtilizacao($utilizacao);

        $this->assertRecusado('utilizacao', 'já está anulada', fn () => $this->anularUtilizacao($utilizacao));
        $this->assertSame(EstadoUtilizacaoCredito::ANULADA, $utilizacao->fresh()->estado);
    }

    public function test_as_utilizacoes_combinadas_no_mesmo_acto_ficam_activas(): void
    {
        $this->regras(parcial: true);
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]); // 500.000 de crédito
        $combinado = $this->pagar($this->matricula, 2_000_000, [[$this->p['out'], 2_500_000]], credito: 500_000);

        $this->anularPagamento($combinado);

        $combinada = CreditoUtilizacao::query()->sole();
        $this->assertSame((int) $combinado->id, (int) $combinada->pagamento_id, 'O8: rastreada pelo pagamento do mesmo acto.');
        $this->assertSame([$combinada->id], $combinado->fresh()->utilizacoesCombinadas->pluck('id')->all(), 'O detalhe e o modal de anulação listam-na.');
        $this->assertSame(EstadoUtilizacaoCredito::ACTIVA, $combinada->estado, 'D14/O8: independentes.');
        $out = $this->p['out']->fresh();
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $out->estado);
        $this->assertSame(500_000, $out->valor_pago->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_a_referencia_fica_livre_depois_de_anular(): void
    {
        $this->regras();
        $errado = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], referencia: 'TRF-55');
        $this->anularPagamento($errado);

        $certo = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], referencia: 'TRF-55');

        $this->assertSame(EstadoPagamento::CONFIRMADO, $certo->estado);
        $this->assertPagamentosCoerentes();
    }

    public function test_referencia_usada_num_pagamento_anulado_devolve_aviso_sem_bloquear(): void
    {
        $this->regras();
        $errado = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], referencia: 'TRF-77');
        $servico = app(DistribuicaoDePagamentoService::class);
        $metodo = (int) $errado->metodo_pagamento_id;

        $this->assertNull($servico->avisoDeReferencia($metodo, 'TRF-77'), 'Confirmado: é o bloqueio de D5, não um aviso.');

        $this->anularPagamento($errado);

        $this->assertStringContainsString($errado->numero, (string) $servico->avisoDeReferencia($metodo, 'TRF-77'));
        $this->assertNull($servico->avisoDeReferencia($metodo, 'OUTRA'));
        $this->assertNull($servico->avisoDeReferencia($metodo, null));
        $this->assertNull($servico->avisoDeReferencia($this->metodoPagamento('TPA')->id, 'TRF-77'), 'Outro método: sem aviso.');

        $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], referencia: 'TRF-77'); // não bloqueia
        $this->assertSame(2, Pagamento::query()->count());
    }

    public function test_pagamento_de_matricula_eliminada_ainda_se_anula(): void
    {
        $this->regras();
        $pendente = $this->matriculaActiva($this->p['set']->matricula->turma, '2026-09-01', EstadoMatriculaEnum::PENDENTE);
        $adiantamento = $this->pagar($pendente, 1_000_000); // sem propinas: tudo crédito
        $pendente->delete();

        $this->assertSame(EstadoPagamento::ANULADO, $this->anularPagamento($adiantamento)->estado);
    }

    public function test_motivo_curto_recusado_no_dto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MotivoDeAnulacaoDTO(' abc ');
    }
}
