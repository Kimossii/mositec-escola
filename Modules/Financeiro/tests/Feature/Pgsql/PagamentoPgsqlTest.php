<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Actions\AnularPropinaAction;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

/**
 * O que só o PostgreSQL prova em Pagamentos-A: CHECK e triggers de imutabilidade de pagamentos, distribuições,
 * créditos e utilizações, e o índice único parcial da referência. SQL cru só aqui (testes), nunca no código dos módulos.
 */
#[Group('pgsql')]
class PagamentoPgsqlTest extends PgsqlTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;

    private Matricula $matricula;

    private Propina $set;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->set = $this->propina($this->matricula, $plano, 1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Num savepoint: o erro aborta só o savepoint e a transacção do teste continua utilizável. */
    private function assertRecusadoPelaBase(string $sqlstate, string $texto, Closure $operacao): void
    {
        try {
            DB::transaction($operacao);
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertStringContainsString($texto, $e->getMessage());

            return;
        }

        $this->fail("A base devia ter recusado ({$texto}).");
    }

    public function test_pagamento_so_aceita_a_anulacao_e_nunca_se_apaga(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]);
        $linha = fn () => DB::table('pagamentos')->where('id', $pagamento->id);

        $this->assertRecusadoPelaBase('23000', 'nunca se edita', fn () => $linha()->update(['valor' => 1]));
        $this->assertRecusadoPelaBase('23000', 'nunca se edita', fn () => $linha()->update(['data_pagamento' => '2026-10-01']));
        $this->assertRecusadoPelaBase('23000', 'nunca se elimina', fn () => $linha()->delete());
        $this->assertRecusadoPelaBase('23514', 'pagamentos_anulacao_check', fn () => $linha()->update(['estado' => 2, 'estado_descricao' => 'Anulado']));
        $this->assertRecusadoPelaBase('23514', 'pagamentos_valor_check', fn () => DB::table('pagamentos')->insert(array_merge(
            array_diff_key((array) $linha()->first(), ['id' => true]), ['numero' => '2026-9999', 'valor' => 0, 'referencia' => null],
        )));

        DB::transaction(fn () => $linha()->update(['estado' => 2, 'estado_descricao' => 'Anulado', 'motivo_anulacao' => 'Teste', 'anulado_em' => now()]));
        $this->assertRecusadoPelaBase('23000', 'nunca se edita', fn () => $linha()->update(['estado' => 1, 'estado_descricao' => 'Confirmado', 'motivo_anulacao' => null, 'anulado_em' => null]));
    }

    public function test_pagamento_recusa_cambio_e_moeda_invalidos(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]);
        $copia = fn (array $alterar) => fn () => DB::table('pagamentos')->insert(array_merge(
            array_diff_key((array) DB::table('pagamentos')->where('id', $pagamento->id)->first(), ['id' => true]),
            ['numero' => '2026-9998', 'referencia' => null],
            $alterar,
        ));

        $this->assertRecusadoPelaBase('23514', 'pagamentos_cambio_check', $copia(['cambio_usd' => 0]));
        $this->assertRecusadoPelaBase('23514', 'pagamentos_moeda_check', $copia(['moeda' => 'AO']));
    }

    public function test_distribuicoes_sao_imutaveis_e_positivas(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
        $distribuicao = fn () => DB::table('pagamento_propinas')->where('pagamento_id', $pagamento->id);

        $this->assertRecusadoPelaBase('23000', 'imutável', fn () => $distribuicao()->update(['valor' => 1]));
        $this->assertRecusadoPelaBase('23000', 'imutável', fn () => $distribuicao()->delete());
    }

    public function test_credito_coerente_e_com_origem_imutavel(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]); // 500.000 de crédito
        $credito = fn () => DB::table('creditos')->where('origem_pagamento_id', $pagamento->id);

        $this->assertRecusadoPelaBase('23000', 'nunca mudam', fn () => $credito()->update(['valor' => 1]));
        // Viola dois CHECK; o PostgreSQL verifica-os por ordem alfabética do nome, por isso só se afirma o prefixo.
        $this->assertRecusadoPelaBase('23514', 'creditos_', fn () => $credito()->update(['valor_utilizado' => 600_000]));
        $this->assertRecusadoPelaBase('23514', 'creditos_estado_coerente_check', fn () => $credito()->update(['estado' => 2, 'estado_descricao' => 'Esgotado']));
        $this->assertRecusadoPelaBase('23000', 'nunca se elimina', fn () => $credito()->delete());

        DB::transaction(fn () => $credito()->update(['estado' => 3, 'estado_descricao' => 'Anulado']));
        $this->assertRecusadoPelaBase('23000', 'terminal', fn () => $credito()->update(['estado' => 1, 'estado_descricao' => 'Disponível']));
    }

    public function test_utilizacao_so_passa_a_anulada(): void
    {
        $this->regras(parcial: true);
        $out = $this->propina($this->matricula, $this->set->plano, 2);
        $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]);
        $utilizacao = $this->aplicarCredito($out, 500_000);
        $linha = fn () => DB::table('credito_utilizacoes')->where('id', $utilizacao->id);

        $this->assertRecusadoPelaBase('23000', 'Activa a Anulada', fn () => $linha()->update(['valor' => 1]));
        $this->assertRecusadoPelaBase('23514', 'credito_utilizacoes_anulacao_check', fn () => $linha()->update(['estado' => 2, 'estado_descricao' => 'Anulada']));
        $this->assertRecusadoPelaBase('23000', 'nunca se elimina', fn () => $linha()->delete());
    }

    public function test_utilizacao_combinada_nao_muda_de_pagamento(): void
    {
        $this->regras(parcial: true);
        $out = $this->propina($this->matricula, $this->set->plano, 2);
        $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]); // 500.000 de crédito
        $combinado = $this->pagar($this->matricula, 2_000_000, [[$out, 2_500_000]], credito: 500_000);
        $linha = fn () => DB::table('credito_utilizacoes')->where('pagamento_id', $combinado->id);

        $this->assertSame(1, $linha()->count(), 'O8: a utilização do mesmo acto guarda o pagamento.');
        $this->assertRecusadoPelaBase('23000', 'Activa a Anulada', fn () => $linha()->update(['pagamento_id' => null]));

        $this->anularPagamento($combinado);
        $this->assertSame(1, $linha()->where('estado', 1)->count(), 'D14/O8: continua Activa depois de anular o pagamento.');
    }

    public function test_referencia_unica_so_entre_confirmados(): void
    {
        $this->regras(parcial: true);
        $primeiro = $this->pagar($this->matricula, 100_000, [[$this->set, 100_000]], referencia: 'TRF-1');
        $copia = fn () => DB::table('pagamentos')->insert(array_merge(
            array_diff_key((array) DB::table('pagamentos')->where('id', $primeiro->id)->first(), ['id' => true]),
            ['numero' => '2026-0999', 'estado' => 1, 'estado_descricao' => 'Confirmado', 'motivo_anulacao' => null, 'anulado_por' => null, 'anulado_em' => null],
        ));

        $this->assertRecusadoPelaBase('23505', 'pagamentos_referencia_confirmada_unique', $copia);

        $this->anularPagamento($primeiro);
        DB::transaction($copia); // o anulado libertou a referência
        $this->assertSame(2, DB::table('pagamentos')->where('referencia', 'TRF-1')->count());
    }

    public function test_ciclo_completo_sob_os_check_das_propinas(): void
    {
        $this->regras(parcial: true);
        $a = $this->pagar($this->matricula, 1_000_000, [[$this->set, 1_000_000]], data: '2026-10-05');
        $b = $this->pagar($this->matricula, 1_500_000, [[$this->set, 1_500_000]]);
        $this->assertSame(EstadoCobranca::PAGA, $this->set->fresh()->estado);

        $this->anularPagamento($b);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->set->fresh()->estado);
        $this->anularPagamento($a);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->set->fresh()->estado);

        $this->assertSame(EstadoCobranca::ANULADA, app(AnularPropinaAction::class)->executar(
            $this->set->fresh(), new MotivoDeAnulacaoDTO('Ciclo de teste'), null,
        )->estado);
        $this->assertPagamentosCoerentes();
    }
}
