<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\ContribuicaoDePagamento;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

/**
 * O "hoje" de negócio é o da escola (Africa/Luanda por omissão), não o de UTC.
 */
class FusoHorarioFinanceiroTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function congelar(string $instante): void
    {
        Carbon::setTestNow(CarbonImmutable::parse($instante, 'UTC'));
    }

    public function test_contribuicao_com_a_data_de_hoje_em_luanda_e_aceite_as_23h30_utc(): void
    {
        $this->congelar('2026-10-10 23:30:00');

        $contribuicao = new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(100), CarbonImmutable::parse('2026-10-11'));

        $this->assertSame('2026-10-11', $contribuicao->data->toDateString());
    }

    public function test_contribuicao_de_depois_de_amanha_local_continua_recusada(): void
    {
        $this->congelar('2026-10-10 23:30:00');

        $this->expectException(InvalidArgumentException::class);

        new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(100), CarbonImmutable::parse('2026-10-12'));
    }

    public function test_com_o_fuso_utc_a_data_local_de_amanha_e_futura(): void
    {
        $this->congelar('2026-10-10 23:30:00');
        Estabelecimento::current()->update(['fuso_horario' => 'UTC']);

        $this->expectException(InvalidArgumentException::class);

        new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(100), CarbonImmutable::parse('2026-10-11'));
    }

    public function test_recalculo_aceita_contribuicao_do_dia_local(): void
    {
        $this->congelar('2026-10-10 23:30:00');
        $this->seed(PermissaoDatabaseSeeder::class);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);
        $this->fontePagamentoFalsa()->definir($propina, [[1_000_000, '2026-10-11']]);

        $recalculada = DB::transaction(
            fn () => app(RecalcularPropina::class)->executar($propina),
        );

        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $recalculada->estado);
    }

    public function test_em_atraso_vira_a_meia_noite_local_e_nao_a_uma_da_manha(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2); // limite 2026-10-15
        $relogio = app(RelogioDoTenant::class);

        // 15/10 23:30 em Luanda = 22:30 UTC: ainda não está em atraso.
        $this->congelar('2026-10-15 22:30:00');
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->estadoResolvido($relogio->hoje()));

        // 16/10 00:30 em Luanda = 23:30 UTC de 15/10: já em atraso (com UTC ainda não estaria).
        $this->congelar('2026-10-15 23:30:00');
        $this->assertSame(EstadoCobranca::EM_ATRASO, $propina->estadoResolvido($relogio->hoje()));
    }

    public function test_cambio_com_a_data_de_hoje_local_e_aceite_e_amanha_local_recusado(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = $this->adminEscola();
        $this->congelar('2026-10-10 23:30:00');
        $rota = route('financeiro.configuracao.moeda-cambio.cambios.store');

        $this->actingAs($admin)->post($rota, ['data' => '2026-10-11', 'taxa' => '910'])->assertSessionHasNoErrors();
        $this->post($rota, ['data' => '2026-10-12', 'taxa' => '910'])->assertSessionHasErrors('data');
    }

    public function test_o_cambio_do_tenant_a_nao_usa_o_fuso_do_tenant_b(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => Estabelecimento::current()->update(['fuso_horario' => 'Pacific/Kiritimati']));
        $this->congelar('2026-10-10 23:30:00');

        $this->expectException(InvalidArgumentException::class);

        new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(100), CarbonImmutable::parse('2026-10-12'));
    }
}
