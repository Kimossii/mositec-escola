<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

/**
 * O que só o PostgreSQL prova em F1: CHECK, índices únicos parciais, bigint/date de ida e volta e o
 * filtro SQL do estado resolvido no motor de produção. Corre só com a base mositec_escola_test.
 */
#[Group('pgsql')]
class PropinaPgsqlTest extends PgsqlTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;

    /**
     * Corre a operação num savepoint (DB::transaction aninhada): no PostgreSQL um erro aborta a transacção
     * inteira, e o savepoint deixa a do RefreshDatabase utilizável a seguir. O PostgreSQL verifica os
     * CHECK por ordem alfabética do nome e reporta o primeiro que falha: por isso aceitam-se vários nomes.
     *
     * @param  list<string>  $restricoes
     */
    private function assertRecusadoPelaBase(string $sqlstate, array $restricoes, Closure $operacao): void
    {
        try {
            DB::transaction($operacao);
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertMatchesRegularExpression('/' . implode('|', array_map('preg_quote', $restricoes)) . '/', $e->getMessage());

            return;
        }

        $this->fail('A base de dados devia ter recusado a operação (' . implode(', ', $restricoes) . ').');
    }

    private function umaPropina(): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano);
    }

    public function test_check_valor_pago_entre_zero_e_o_valor(): void
    {
        $propina = $this->umaPropina();
        $valor = $propina->valor->unidadesMenores();
        $linha = fn () => DB::table('propinas')->where('id', $propina->id);

        $this->assertRecusadoPelaBase('23514', ['propinas_valor_pago_intervalo_check', 'propinas_estado_coerente_check', 'propinas_capital_liquidado_check'],
            fn () => $linha()->update(['valor_pago' => $valor + 1, 'estado' => 3, 'capital_liquidado_em' => '2026-09-05']));
        $this->assertRecusadoPelaBase('23514', ['propinas_valor_pago_intervalo_check', 'propinas_estado_coerente_check'],
            fn () => $linha()->update(['valor_pago' => -1]));

        // O limite (valor_pago = valor, Paga, com data de liquidação) é aceite.
        $linha()->update(['valor_pago' => $valor, 'estado' => 3, 'capital_liquidado_em' => '2026-09-05']);
        $this->assertSame($valor, $propina->fresh()->valor_pago->unidadesMenores());
    }

    public function test_check_valor_positivo_estado_e_datas_de_cancelamento(): void
    {
        $propina = $this->umaPropina();
        $linha = fn () => DB::table('propinas')->where('id', $propina->id);

        $this->assertRecusadoPelaBase('23514', ['propinas_valor_positivo_check', 'propinas_capital_liquidado_check'],
            fn () => $linha()->update(['valor' => 0]));
        $this->assertRecusadoPelaBase('23514', ['propinas_estado_coerente_check'],
            fn () => $linha()->update(['estado' => 3]));
        $this->assertRecusadoPelaBase('23514', ['propinas_cancelada_check'],
            fn () => $linha()->update(['estado' => 4]));
        $this->assertRecusadoPelaBase('23514', ['propinas_estado_check', 'propinas_estado_coerente_check'],
            fn () => $linha()->update(['estado' => 7]));
        $this->assertRecusadoPelaBase('23514', ['propinas_vencimento_check'],
            fn () => $linha()->update(['data_vencimento' => '2026-08-31', 'data_limite' => '2026-09-05']));
    }

    public function test_indices_parciais_impedem_duas_activas_e_permitem_regerar_depois_de_cancelar(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $outroPlano = $this->plano($ano, 'Outro Plano');
        $primeira = $this->propina($matricula, $plano);

        $this->assertRecusadoPelaBase('23505', ['propinas_periodo_activo_unique'], fn () => $this->propina($matricula, $outroPlano));
        $this->assertRecusadoPelaBase('23505', ['propinas_plano_ordem_activo_unique', 'propinas_periodo_activo_unique'], fn () => $this->propina($matricula, $plano));

        $this->comEstado($primeira, EstadoCobranca::CANCELADA);
        $segunda = $this->propina($matricula, $plano);

        $this->assertSame(2, Propina::query()->where('matricula_id', $matricula->id)->count());
        $this->assertSame([$segunda->id], Propina::query()->activas()->pluck('id')->all());
        $this->assertRecusadoPelaBase('23505', ['propinas_periodo_activo_unique'], fn () => $this->propina($matricula, $outroPlano));
    }

    public function test_dinheiro_cambio_e_datas_fazem_ida_e_volta_sem_perdas(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $maximo = 999_999_999_999_999; // 12 dígitos inteiros numa moeda de 3 casas decimais
        $propina = $this->propina($matricula, $plano, 1, ['valor' => Dinheiro::deUnidadesMenores($maximo), 'cambio_usd' => 999_999_999_999_999]);

        $linha = DB::table('propinas')->where('id', $propina->id)->first();
        $this->assertSame($maximo, (int) $linha->valor);
        $this->assertSame($maximo, (int) $linha->valor_original);
        $this->assertSame('2026-09-01', (string) $linha->periodo_inicio);
        $this->assertSame('2026-09-15', (string) $linha->data_limite);

        $lida = Propina::query()->findOrFail($propina->id);
        $this->assertSame($maximo, $lida->valor->unidadesMenores());
        $this->assertSame($maximo, $lida->valor_original->unidadesMenores());
        $this->assertSame(999_999_999_999_999, $lida->cambio_usd);
        $this->assertSame('2026-09-30', $lida->periodo_fim->toDateString());
    }

    public function test_o_filtro_sql_do_estado_resolvido_coincide_com_o_php_no_postgresql(): void
    {
        $this->cenarioDeEstados();

        foreach (['2026-08-31', '2026-09-01', '2026-09-15', '2026-09-16', '2026-10-15', '2026-10-16', '2027-07-01'] as $dia) {
            $hoje = CarbonImmutable::parse($dia);
            $porEstado = Propina::query()->orderBy('id')->get()->groupBy(fn (Propina $p) => $p->estadoResolvido($hoje)->value);

            foreach (EstadoCobranca::cases() as $estado) {
                $this->assertSame(
                    ($porEstado[$estado->value] ?? collect())->pluck('id')->all(),
                    Propina::query()->comEstadoResolvido($estado, $hoje)->orderBy('id')->pluck('id')->all(),
                    "{$estado->label()} em {$dia}",
                );
            }
        }
    }

    public function test_ciclo_completo_do_recalculo_sob_os_check(): void
    {
        $fonte = $this->fontePagamentoFalsa();
        $propina = $this->umaPropina();
        $valor = $propina->valor->unidadesMenores();
        $recalcular = fn () => app(RecalcularPropina::class)->executar($propina);

        $fonte->definir($propina, [[1_000_000, '2026-09-05']]);
        $r = $recalcular();
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $r->estado);
        $this->assertNull($r->capital_liquidado_em);

        $fonte->definir($propina, [[1_000_000, '2026-09-05'], [$valor - 1_000_000, '2026-09-20']]);
        $r = $recalcular();
        $this->assertSame(EstadoCobranca::PAGA, $r->estado);
        $this->assertSame($valor, $r->valor_pago->unidadesMenores());
        $this->assertSame('2026-09-20', $r->capital_liquidado_em->toDateString());

        $fonte->definir($propina, []);
        $r = $recalcular();
        $this->assertSame(EstadoCobranca::EM_ABERTO, $r->estado);
        $this->assertSame(0, $r->valor_pago->unidadesMenores());
        $this->assertNull($r->capital_liquidado_em);
        $this->assertPropinasCoerentes();
    }

    public function test_check_motivos_tolerancia_e_data_limite(): void
    {
        $propina = $this->umaPropina();
        $linha = fn () => DB::table('propinas')->where('id', $propina->id);

        $this->assertRecusadoPelaBase('23514', ['propinas_cancelada_motivo_check'],
            fn () => $linha()->update(['estado' => 4, 'cancelado_em' => now()]));
        $this->assertRecusadoPelaBase('23514', ['propinas_cancelada_motivo_check'],
            fn () => $linha()->update(['estado' => 4, 'cancelado_em' => now(), 'motivo_cancelamento' => '  ']));
        $this->assertRecusadoPelaBase('23514', ['propinas_anulada_motivo_check'],
            fn () => $linha()->update(['estado' => 5, 'anulado_em' => now()]));
        $this->assertRecusadoPelaBase('23514', ['propinas_cancelada_motivo_check'],
            fn () => $linha()->update(['cancelado_por' => DB::table('users')->value('id') ?? 1]));
        $this->assertRecusadoPelaBase('23514', ['propinas_tolerancia_check', 'propinas_data_limite_check', 'propinas_vencimento_check'],
            fn () => $linha()->update(['dias_tolerancia' => -1]));
        $this->assertRecusadoPelaBase('23514', ['propinas_data_limite_check'],
            fn () => $linha()->update(['data_limite' => '2026-09-30']));

        $linha()->update(['estado' => 4, 'cancelado_em' => now(), 'motivo_cancelamento' => 'Erro de matrícula']);
        $this->assertSame(4, (int) $linha()->value('estado'));
    }

    public function test_o_plano_so_muda_para_um_do_mesmo_ano_lectivo(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $planoOutroAno = $this->plano($outroAno, 'Propina 2027');

        try {
            $propina->forceFill(['plano_propina_id' => $planoOutroAno->id])->save();
            $this->fail('Devia recusar o plano de outro ano lectivo.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('mesmo ano lectivo', $e->getMessage());
        }

        $mesmoAno = $this->plano($ano, 'Outro Plano');
        $propina->refresh()->forceFill(['plano_propina_id' => $mesmoAno->id])->save();
        $this->assertSame($mesmoAno->id, $propina->refresh()->plano_propina_id);
    }
}
