<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

/**
 * Nota: o RefreshDatabase corre cada teste dentro de uma transacção, por isso chamar o serviço
 * directamente já está "dentro de uma transacção do chamador".
 */
class RecalcularPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function recalcular(Propina $propina): Propina
    {
        return app(RecalcularPropina::class)->executar($propina);
    }

    private function umaPropina(): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano); // 25.000,00
    }

    private function assertEstado(Propina $propina, EstadoCobranca $estado, int $pago, ?string $liquidadaEm): void
    {
        $this->assertSame($estado, $propina->estado);
        $this->assertSame($estado->label(), $propina->estado_descricao);
        $this->assertSame($pago, $propina->valor_pago->unidadesMenores());
        $this->assertSame($liquidadaEm, $propina->capital_liquidado_em?->toDateString());
    }

    public function test_sem_fontes_registadas_o_valor_pago_volta_a_zero_e_fica_em_aberto(): void
    {
        $propina = $this->comEstado($this->umaPropina(), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000); // cache desactualizada

        $this->assertEstado($this->recalcular($propina), EstadoCobranca::EM_ABERTO, 0, null);
        $this->assertPropinasCoerentes();
    }

    public function test_transicoes_automaticas_entre_aberta_parcial_e_paga(): void
    {
        $fonte = $this->fontePagamentoFalsa();
        $propina = $this->umaPropina();

        $fonte->definir($propina, [[1_000_000, '2026-09-05']]);
        $this->assertEstado($this->recalcular($propina), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000, null);

        $fonte->definir($propina, [[1_000_000, '2026-09-05'], [1_500_000, '2026-09-20']]);
        $this->assertEstado($this->recalcular($propina), EstadoCobranca::PAGA, 2_500_000, '2026-09-20');

        $fonte->definir($propina, [[1_500_000, '2026-09-20']]); // o pagamento de 05/09 foi anulado
        $this->assertEstado($this->recalcular($propina), EstadoCobranca::PARCIALMENTE_PAGA, 1_500_000, null);

        $fonte->definir($propina, []);
        $this->assertEstado($this->recalcular($propina), EstadoCobranca::EM_ABERTO, 0, null);

        $this->assertPropinasCoerentes();
    }

    public function test_recalcular_duas_vezes_com_as_mesmas_contribuicoes_nao_grava_nada(): void
    {
        $fonte = $this->fontePagamentoFalsa();
        $propina = $this->umaPropina();
        $fonte->definir($propina, [[2_500_000, '2026-09-05']]);

        $primeira = $this->recalcular($propina);
        $this->travel(1)->days();
        $segunda = $this->recalcular($primeira);

        $this->assertEstado($segunda, EstadoCobranca::PAGA, 2_500_000, '2026-09-05');
        $this->assertSame($primeira->getAttributes(), $segunda->getAttributes()); // nem updated_at muda
    }

    public function test_soma_todas_as_fontes_e_liquida_na_data_da_contribuicao_que_fecha_o_saldo(): void
    {
        $pagamentos = $this->fontePagamentoFalsa();
        $creditos = $this->fontePagamentoFalsa('fonte.credito.falsa');
        $propina = $this->umaPropina();

        $pagamentos->definir($propina, [[1_500_000, '2026-10-02']]);
        $creditos->definir($propina, [[1_000_000, '2026-09-10']]); // crédito usado antes do pagamento

        $this->assertEstado($this->recalcular($propina), EstadoCobranca::PAGA, 2_500_000, '2026-10-02');
    }

    public function test_excesso_lanca_e_a_transacao_do_chamador_reverte_por_inteiro(): void
    {
        $fonte = $this->fontePagamentoFalsa();
        $propina = $this->umaPropina();
        $plano = $propina->plano;
        $fonte->definir($propina, [[1_000_000, '2026-09-05']]);
        DB::transaction(fn () => $this->recalcular($propina));

        $fonte->definir($propina, [[1_000_000, '2026-09-05'], [2_000_000, '2026-09-06']]);

        try {
            DB::transaction(function () use ($propina, $plano) {
                $plano->forceFill(['nome' => 'Alterado na mesma transacção'])->save();
                $this->recalcular($propina);
            });
            $this->fail('O excesso devia lançar.');
        } catch (LogicException $e) {
            $this->assertSame("O valor pago (3000000) excede o valor da propina (2500000) na propina {$propina->id}.", $e->getMessage());
        }

        $this->assertSame('Propina Mensal', $plano->fresh()->nome);
        $this->assertEstado($propina->fresh(), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000, null);
    }

    public function test_canceladas_e_anuladas_nunca_sao_alteradas(): void
    {
        $fonte = $this->fontePagamentoFalsa();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $cancelada = $this->comEstado($this->propina($matricula, $plano, 1), EstadoCobranca::CANCELADA);
        $anulada = $this->comEstado($this->propina($matricula, $plano, 2), EstadoCobranca::ANULADA);

        foreach ([$cancelada, $anulada] as $propina) {
            $this->assertSame($propina->getAttributes(), $this->recalcular($propina)->getAttributes());
        }

        $fonte->definir($cancelada, [[1_000, '2026-09-05']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Uma propina cancelada ou anulada não pode ter pagamentos activos.');

        $this->recalcular($cancelada);
    }

    public function test_fora_de_uma_transacao_lanca_antes_de_ler_a_base(): void
    {
        $propina = $this->umaPropina();

        // Sai da transacção do RefreshDatabase para simular uma chamada sem DB::transaction. Os dados criados
        // acima desaparecem, mas o serviço tem de lançar antes de ler a base.
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        try {
            $this->recalcular($propina);
            $this->fail('Sem transacção devia lançar.');
        } catch (LogicException $e) {
            $this->assertSame('Bloquear propinas exige uma transacção aberta (DB::transaction) do chamador.', $e->getMessage());
        }

        $this->expectException(LogicException::class);

        app(BloqueioDePropinas::class)->bloquear([$propina->id]);
    }

    public function test_bloqueio_ordena_por_id_sem_repetidos_e_ignora_outro_tenant(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $a = $this->propina($matricula, $plano, 1);
        $b = $this->propina($matricula, $plano, 2);
        $c = $this->propina($matricula, $plano, 3);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dela = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

            return $this->propina($matricula, $plano)->id;
        });

        $ids = DB::transaction(fn () => app(BloqueioDePropinas::class)->bloquear([$c->id, $a->id, $c->id, $dela, $b->id])->pluck('id')->all());

        $this->assertSame([$a->id, $b->id, $c->id], $ids);
        $this->assertTrue(DB::transaction(fn () => app(BloqueioDePropinas::class)->bloquear([])->isEmpty()));
    }

    public function test_recalcular_uma_propina_de_outro_tenant_lanca(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dela = $this->noTenant($outro, fn () => $this->umaPropina());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A propina a recalcular não existe no tenant corrente.');

        $this->recalcular($dela);
    }
}
