<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\PgsqlTestCase;

/**
 * O que só o PostgreSQL prova em F4: os CHECK das tabelas novas, o reajuste numa só escrita sob
 * propinas_estado_coerente_check, a reversão depois de uma falha a meio (sem transacção abortada) e o aborto por
 * propina de outro ano. Corre só com a base mositec_escola_test.
 */
#[Group('pgsql')]
class AlteracaoPrecoPgsqlTest extends PgsqlTestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Corre num savepoint: um erro no PostgreSQL aborta a transacção e o savepoint deixa a do teste utilizável. */
    private function assertRecusadoPelaBase(string $restricao, Closure $operacao): void
    {
        try {
            DB::transaction($operacao);
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertStringContainsString($restricao, $e->getMessage());

            return;
        }

        $this->fail("A base devia ter recusado ({$restricao}).");
    }

    public function test_check_das_alteracoes_e_dos_ajustes(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 6);
        $alteracao = $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS);
        $linha = fn (array $sobrepor) => array_merge([
            'tenant_id' => $alteracao->tenant_id, 'ano_lectivo_id' => $plano->ano_lectivo_id, 'plano_propina_id' => $plano->id,
            'plano_novo_id' => null, 'modo' => 1, 'modo_descricao' => 'x', 'valor_anterior' => 100, 'valor_novo' => 200,
            'mes_efeito' => '2027-02-01', 'motivo' => 'Motivo', 'total_propinas_afectadas' => 0, 'impressao_digital' => str_repeat('a', 64),
        ], $sobrepor);

        $this->assertRecusadoPelaBase('alteracoes_preco_plano_valores_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['valor_novo' => 100])));
        $this->assertRecusadoPelaBase('alteracoes_preco_plano_valores_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['valor_anterior' => 0])));
        $this->assertRecusadoPelaBase('alteracoes_preco_plano_mes_efeito_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['mes_efeito' => '2027-02-15'])));
        $this->assertRecusadoPelaBase('alteracoes_preco_plano_motivo_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['motivo' => '   '])));
        $this->assertRecusadoPelaBase('alteracoes_preco_plano_modo_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['modo' => 3])));
        $this->assertRecusadoPelaBase('alteracoes_preco_plano_total_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['total_propinas_afectadas' => -1])));
        $this->assertRecusadoPelaBase('alteracoes_preco_plano_planos_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['plano_novo_id' => $plano->id])));
        $base = [
            'tenant_id' => $alteracao->tenant_id, 'alteracao_preco_id' => $alteracao->id, 'propina_id' => $propina->id,
            'valor_anterior' => 100, 'valor_novo' => 200, 'valor_pago_no_momento' => 0, 'plano_anterior_id' => $plano->id, 'motivo' => 'M',
        ];
        $this->assertRecusadoPelaBase('propina_ajustes_valor_pago_check', fn () => DB::table('propina_ajustes')->insert(array_merge($base, ['valor_pago_no_momento' => 300])));
        $this->assertRecusadoPelaBase('propina_ajustes_valores_check', fn () => DB::table('propina_ajustes')->insert(array_merge($base, ['valor_novo' => 100])));
        $this->assertRecusadoPelaBase('propina_ajustes_motivo_check', fn () => DB::table('propina_ajustes')->insert(array_merge($base, ['motivo' => ' '])));
    }

    public function test_descida_abaixo_do_pago_passa_sob_os_check_numa_so_escrita(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $parcial = $this->comEstado($this->propina($matricula, $plano, 7), EstadoCobranca::PARCIALMENTE_PAGA, 2_000_000);
        $this->fontePagamentoFalsa()->definir($parcial, [[2_000_000, '2026-10-05']]);

        $this->alterarPreco($plano, '2027-02', 1_500_000, ModoAlteracaoPreco::APLICAR_GERADAS);

        $linha = DB::selectOne('select valor, valor_pago, estado, capital_liquidado_em from propinas where id = ?', [$parcial->id]);
        $this->assertSame([2_000_000, 2_000_000, 3, '2026-10-05'], [(int) $linha->valor, (int) $linha->valor_pago, (int) $linha->estado, substr((string) $linha->capital_liquidado_em, 0, 10)]);
        $this->assertSame(2_000_000, (int) DB::selectOne('select valor_novo from propina_ajustes where propina_id = ?', [$parcial->id])->valor_novo);
        $this->assertSame(2_000_000, (int) DB::selectOne('select valor_pago_no_momento from propina_ajustes where propina_id = ?', [$parcial->id])->valor_pago_no_momento);
        $this->assertPropinasCoerentes();
    }

    public function test_propinas_estado_coerente_recusa_baixar_o_valor_sem_mudar_o_estado_no_mesmo_update(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $parcial = $this->comEstado($this->propina($matricula, $plano, 7), EstadoCobranca::PARCIALMENTE_PAGA, 2_000_000);

        // Baixar o valor até ao pago, mantendo estado 2 (Parcial): incoerente. É por isso que o reajuste é UMA escrita.
        // (Violaria o estado coerente e a data de liquidação; o PostgreSQL nomeia o primeiro que avaliar.)
        try {
            DB::transaction(fn () => DB::update('update propinas set valor = 2000000 where id = ?', [$parcial->id]));
            $this->fail('A base devia ter recusado.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? null);
            $this->assertMatchesRegularExpression('/propinas_(estado_coerente|capital_liquidado)_check/', $e->getMessage());
        }

        // Mudar o estado sem a data de liquidação: recusa o outro CHECK.
        $this->assertRecusadoPelaBase('propinas_capital_liquidado_check', fn () => DB::update('update propinas set valor = 2000000, estado = 3, estado_descricao = ? where id = ?', ['Paga', $parcial->id]));
        // Tudo no mesmo UPDATE (o que o RecalcularPropina faz): passa.
        DB::update('update propinas set valor = 2000000, estado = 3, estado_descricao = ?, capital_liquidado_em = ? where id = ?', ['Paga', '2026-10-05', $parcial->id]);
        $this->assertSame(3, (int) DB::selectOne('select estado from propinas where id = ?', [$parcial->id])->estado);
        // E o valor nunca desce abaixo do pago.
        try {
            DB::transaction(fn () => DB::update('update propinas set valor = 1999999 where id = ?', [$parcial->id]));
            $this->fail('A base devia ter recusado.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? null);
            $this->assertMatchesRegularExpression('/propinas_(valor_pago_intervalo|capital_liquidado)_check/', $e->getMessage());
        }
    }

    public function test_falha_a_meio_reverte_tudo_e_a_ligacao_continua_utilizavel(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        foreach (range(6, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $antes = $this->fotografia();
        $vezes = 0;
        PropinaAjuste::creating(function () use (&$vezes) {
            if (++$vezes === 3) {
                throw new RuntimeException('Falha injectada: ajuste');
            }
        });

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Motivo', $impressao));
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($antes, $this->fotografia(), 'Tudo revertido no PostgreSQL.');
        $this->assertSame(0, AlteracaoPrecoPlano::query()->count(), 'A ligação continua utilizável (transacção não ficou abortada).');
    }

    public function test_propina_de_outro_ano_aborta_tudo(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 6);
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        DB::table('matriculas')->where('id', $matricula->id)->update(['ano_lectivo_id' => $outroAno->id]);
        $antes = $this->fotografia();

        $this->assertRecusa('plano', 'outro ano lectivo', fn () => app(AlterarPrecoPlanoAction::class)->executar(
            $plano->fresh(),
            $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Motivo', $impressao),
        ));
        $this->assertSame($antes, $this->fotografia());
        $this->assertSame(1, Propina::query()->count());
    }
}
