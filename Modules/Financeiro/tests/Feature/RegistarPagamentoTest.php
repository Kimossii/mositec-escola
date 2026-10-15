<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\RegistarPagamentoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use RuntimeException;
use Tests\TestCase;

class RegistarPagamentoTest extends TestCase
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
        $this->p = ['set' => $this->propina($this->matricula, $plano, 1), 'out' => $this->propina($this->matricula, $plano, 2), 'nov' => $this->propina($this->matricula, $plano, 3)];
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

    private function assertRecusado(string $campo, Closure $operacao): void
    {
        $antes = $this->contagens();

        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
        }

        $this->assertSame($antes, $this->contagens(), 'Nada gravado.');
    }

    public function test_regista_parcial_com_numero_snapshot_autor_e_recalculo(): void
    {
        $this->regras(parcial: true);
        $admin = $this->adminEscola();
        $this->actingAs($admin);

        $pagamento = $this->pagar($this->matricula, 1_000_000, [[$this->p['set'], 1_000_000]], referencia: '  TRF-1  ');

        $this->assertSame('2026-0001', $pagamento->numero);
        $this->assertSame(EstadoPagamento::CONFIRMADO, $pagamento->estado);
        $this->assertSame('TRF-1', $pagamento->referencia);
        $this->assertSame(app(MoedaDoTenant::class)->atual()->codigo, $pagamento->moeda);
        $this->assertSame((int) $this->matricula->aluno_id, $pagamento->aluno_id);
        $this->assertSame($admin->id, (int) $pagamento->criado_por);
        $this->assertCount(1, $pagamento->distribuicoes);
        $this->assertNull($pagamento->credito);

        $set = $this->p['set']->fresh();
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $set->estado);
        $this->assertSame(1_000_000, $set->valor_pago->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_pagamento_com_data_anterior_liquida_o_capital_nessa_data(): void
    {
        $this->regras();

        $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-09-08');

        $set = $this->p['set']->fresh();
        $this->assertSame(EstadoCobranca::PAGA, $set->estado);
        $this->assertSame('2026-09-08', $set->capital_liquidado_em->toDateString());
        $this->assertPagamentosCoerentes();
    }

    public function test_automatica_com_excedente_gera_credito_do_aluno(): void
    {
        $this->regras();

        $pagamento = $this->pagar($this->matricula, 6_000_000);

        $this->assertSame([$this->p['set']->id, $this->p['out']->id], $pagamento->distribuicoes->pluck('propina_id')->all());
        $this->assertSame(1_000_000, $pagamento->credito->valor->unidadesMenores());
        $this->assertSame(EstadoCredito::DISPONIVEL, $pagamento->credito->estado);
        $this->assertSame((int) $this->matricula->aluno_id, (int) $pagamento->credito->aluno_id);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p['nov']->fresh()->estado, 'Novembro é futura e a escola não permite antecipado.');
        $this->assertPagamentosCoerentes();
    }

    public function test_usar_credito_primeiro_e_dinheiro_novo_depois(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $origem = $this->pagar($this->matricula, 6_000_000, [[$this->p['set'], 2_500_000], [$this->p['out'], 2_500_000]]); // 1.000.000 de crédito

        $pagamento = $this->pagar($this->matricula, 1_500_000, [[$this->p['nov'], 2_500_000]], credito: 1_000_000);

        $this->assertSame(1_500_000, $pagamento->distribuicoes->sole()->valor->unidadesMenores(), 'Só o dinheiro novo entra no pagamento.');
        $this->assertNull($pagamento->credito, 'Sem excedente.');
        $utilizacao = CreditoUtilizacao::query()->sole();
        $this->assertSame((int) $pagamento->id, (int) $utilizacao->pagamento_id, 'O8: a utilização guarda o pagamento do mesmo acto.');
        $this->assertSame([(int) $origem->credito->id, $this->p['nov']->id, 1_000_000, '2026-10-16'], [(int) $utilizacao->credito_id, (int) $utilizacao->propina_id, $utilizacao->valor->unidadesMenores(), $utilizacao->data_utilizacao->toDateString()]);
        $this->assertSame(EstadoCredito::ESGOTADO, $origem->credito->fresh()->estado);
        $this->assertSame(EstadoCobranca::PAGA, $this->p['nov']->fresh()->estado);
        $this->assertPagamentosCoerentes();
    }

    public function test_data_do_pagamento_nunca_anterior_a_matricula_nem_futura(): void
    {
        $this->regras();
        $this->assertSame('2026-09-01', $this->matricula->data_matricula->toDateString());

        $this->assertRecusado('data_pagamento', fn () => $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-08-31'));
        $this->assertRecusado('data_pagamento', fn () => $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-10-17'));

        $limite = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-09-01');

        $this->assertSame('2026-09-01', $limite->data_pagamento->toDateString(), 'Igual à data da matrícula passa.');
        $this->assertSame('2026-09-01', $this->p['set']->fresh()->capital_liquidado_em->toDateString());
        $this->assertSame('2026-0001', $limite->numero, 'As recusas não consomem número.');
        $this->assertPagamentosCoerentes();
    }

    public function test_a_data_valida_se_com_a_data_da_matricula_relida_sob_o_lock(): void
    {
        $this->regras();
        $obsoleta = $this->matricula->fresh(); // cópia com data_matricula 2026-09-01
        Matricula::query()->whereKey($this->matricula->id)->update(['data_matricula' => '2026-09-20']);

        $this->assertRecusado('data_pagamento', fn () => app(RegistarPagamentoAction::class)->executar(
            $obsoleta,
            $this->pedidoPagamento(2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-09-10'),
        ));
    }

    public function test_numeracao_sequencial_sem_lacunas_mesmo_com_recusas(): void
    {
        $this->regras(parcial: true);

        $this->assertSame('2026-0001', $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]])->numero);
        $this->assertRecusado('data_pagamento', fn () => $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]], data: '2026-10-17'));
        $this->assertRecusado('distribuicao.0.valor', fn () => $this->pagar($this->matricula, 9_000_000, [[$this->p['set'], 9_000_000]]));
        $this->assertSame('2026-0002', $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]])->numero);
    }

    public function test_o_ano_do_numero_e_o_do_dia_da_escola(): void
    {
        $this->regras();
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC')); // 01/01/2027 00:30 em Luanda

        $this->assertSame('2027-0001', $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2027-01-01')->numero);
    }

    public function test_metodo_inactivo_ou_inexistente_recusa(): void
    {
        $this->regras();
        $inactivo = $this->metodoPagamento('Cheque', activo: false);

        $this->assertRecusado('metodo_pagamento_id', fn () => app(RegistarPagamentoAction::class)->executar(
            $this->matricula,
            $this->pedidoPagamento(2_500_000, [[$this->p['set'], 2_500_000]], metodoId: $inactivo->id),
        ));
        $this->assertRecusado('metodo_pagamento_id', fn () => app(RegistarPagamentoAction::class)->executar(
            $this->matricula,
            $this->pedidoPagamento(2_500_000, [[$this->p['set'], 2_500_000]], metodoId: 999_999),
        ));
    }

    public function test_referencia_duplicada_no_mesmo_metodo_recusa_noutro_metodo_passa(): void
    {
        $this->regras(parcial: true);
        $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]], referencia: 'TRF-9');

        $this->assertRecusado('referencia', fn () => $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]], referencia: 'TRF-9'));

        app(RegistarPagamentoAction::class)->executar(
            $this->matricula,
            $this->pedidoPagamento(100_000, [[$this->p['set'], 100_000]], referencia: 'TRF-9', metodoId: $this->metodoPagamento('TPA')->id),
        );
        $this->assertSame(2, Pagamento::query()->count());
    }

    /**
     * A corrida real (outra sessão grava a mesma referência entre a pré-verificação e o INSERT): o índice único parcial
     * rebenta no INSERT e a violação tem de virar 422 em `referencia`, FORA da transacção, sem gravar nada nem consumir número.
     */
    public function test_violacao_do_indice_da_referencia_na_corrida_vira_422_sem_gravar_nem_consumir_numero(): void
    {
        $this->regras(parcial: true);
        $rival = true;
        $matricula = $this->matricula;
        $metodoId = (int) $this->metodoPagamento()->id;

        Pagamento::creating(function () use (&$rival, $matricula, $metodoId) {
            if ($rival) {
                $rival = false; // o rival é um Pagamento: evita recursão
                $this->pagamentoDirecto($matricula, 100_000, ['numero' => 'RIVAL-0001', 'referencia' => 'TRF-CORRIDA', 'metodo_pagamento_id' => $metodoId]);
            }
        });

        $this->assertRecusado('referencia', fn () => $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]], referencia: 'TRF-CORRIDA'));

        $this->assertFalse($rival, 'Preparação: o hook correu (a pré-verificação não apanhou o rival).');
        $this->assertSame([0, 0, 0, 0], $this->contagens(), 'O rival e tudo o resto foram revertidos.');
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p['set']->fresh()->estado);
        $this->assertSame('2026-0001', $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]], referencia: 'TRF-OUTRA')->numero, 'O número não foi consumido.');
    }

    public function test_segundo_registo_explicito_da_mesma_propina_e_recusado(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]]);

        $this->assertRecusado('distribuicao.0.propina_id', fn () => $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]]));
    }

    public function test_falha_a_meio_reverte_tudo_e_nao_consome_numero(): void
    {
        $this->regras();
        $falhar = true;
        Credito::creating(function () use (&$falhar) {
            if ($falhar) {
                throw new RuntimeException('Falha injectada: crédito');
            }
        });

        try {
            $this->pagar($this->matricula, 6_000_000);
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([0, 0, 0, 0], $this->contagens());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p['set']->fresh()->estado);
        $falhar = false;
        $this->assertSame('2026-0001', $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]])->numero, 'O número não foi consumido.');
    }

    public function test_matricula_eliminada_nao_recebe_pagamentos(): void
    {
        $this->regras();
        $pendente = $this->matriculaActiva($this->p['set']->matricula->turma, '2026-09-01', EstadoMatriculaEnum::PENDENTE);
        $pendente->delete();

        $this->assertRecusado('matricula', fn () => app(RegistarPagamentoAction::class)->executar($pendente, $this->pedidoPagamento(100_000)));
    }
}
