<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\DivisaoDePlanoPropina;
use Modules\Financeiro\Support\ReassociacaoDePropinas;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DivisaoEReassociacaoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function dividir(PlanoPropina $plano, string $mesEfeito, int $valor = 3_000_000, string $nome = 'Novo'): PlanoPropina
    {
        return DB::transaction(fn () => app(DivisaoDePlanoPropina::class)->dividir(
            PlanoPropina::query()->whereKey($plano->id)->lockForUpdate()->firstOrFail(),
            CarbonImmutable::createFromFormat('!Y-m', $mesEfeito),
            Dinheiro::deUnidadesMenores($valor),
            $nome,
        ));
    }

    /** @return list<string> */
    private function chavesDosAlvos(PlanoPropina $plano): array
    {
        $chaves = $plano->alvos()->get()->map(fn (PlanoPropinaAlvo $a) => AlvosDoPlano::chave($a->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'])))->all();
        sort($chaves);

        return $chaves;
    }

    public function test_dividir_encurta_o_antigo_e_cria_o_novo_com_os_mesmos_alvos_e_ano(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turma = $this->turma($ano, $this->nivel('N2'));
        $plano = $this->plano($ano, 'Propina Mensal', ['descricao' => 'Desc'], [['nivel' => $nivel], ['turma' => $turma]]);

        $novo = $this->dividir($plano, '2027-01', 3_000_000, 'Propina Mensal – Fase 2');
        $plano->refresh();

        $this->assertSame(9, $plano->data_inicio->month);
        $this->assertSame(12, $plano->data_fim->month);
        $this->assertSame(2_500_000, $plano->valor->unidadesMenores());
        $this->assertCount(4, $plano->periodos());

        $this->assertSame($plano->ano_lectivo_id, $novo->ano_lectivo_id);
        $this->assertSame('Propina Mensal – Fase 2', $novo->nome);
        $this->assertSame('Desc', $novo->descricao);
        $this->assertSame(Periodicidade::MENSAL, $novo->periodicidade);
        $this->assertSame(1, $novo->intervalo_meses);
        $this->assertSame(1, $novo->data_inicio->month);
        $this->assertSame(6, $novo->data_fim->month);
        $this->assertSame(3_000_000, $novo->valor->unidadesMenores());
        $this->assertSame(Estado::ATIVO->value, $novo->estado);
        $this->assertSame($this->chavesDosAlvos($plano), $this->chavesDosAlvos($novo));
        $this->assertSame('2027-01-01', $novo->periodos()[0]['inicio']);
        $this->assertCount(6, $novo->periodos());
    }

    public function test_dividir_um_plano_trimestral_numa_fronteira_mantem_os_periodos_seguintes(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);

        $novo = $this->dividir($plano, '2026-12');

        $this->assertSame([3], array_column($plano->refresh()->periodos(), 'meses'));
        $this->assertSame([['2026-12-01', 3], ['2027-03-01', 3], ['2027-06-01', 1]], array_map(fn ($p) => [$p['inicio'], $p['meses']], $novo->periodos()));
    }

    public function test_plano_inactivo_divide_e_o_novo_fica_inactivo(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Inactivo', ['estado' => Estado::INATIVO->value]);

        $this->assertSame(Estado::INATIVO->value, $this->dividir($plano, '2027-01')->estado);
    }

    public function test_alterar_valor_in_loco_so_muda_o_valor(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Mensal');

        DB::transaction(fn () => app(DivisaoDePlanoPropina::class)->alterarValor($plano, Dinheiro::deUnidadesMenores(2_800_000)));

        $plano->refresh();
        $this->assertSame(2_800_000, $plano->valor->unidadesMenores());
        $this->assertSame([9, 6], [$plano->data_inicio->month, $plano->data_fim->month]);
    }

    public function test_dividir_e_alterar_valor_exigem_transaccao(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Mensal');

        // O RefreshDatabase envolve o teste numa transacção: sai-se dela para provar a recusa (como no RecalcularPropinaTest).
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        try {
            app(DivisaoDePlanoPropina::class)->alterarValor($plano, Dinheiro::deUnidadesMenores(2_800_000));
            $this->fail('Devia exigir transacção.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('transacção aberta', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        app(DivisaoDePlanoPropina::class)->dividir($plano, CarbonImmutable::parse('2027-01-01'), Dinheiro::deUnidadesMenores(1), 'X');
    }

    public function test_reassociar_muda_so_plano_e_ordem_de_uma_propina_em_aberto_ou_parcial(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $janeiro = $this->comEstado($this->propina($matricula, $plano, 5), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);
        $novo = $this->dividir($plano, '2027-01');

        DB::transaction(fn () => app(ReassociacaoDePropinas::class)->reassociar($janeiro, $novo, 1));
        $janeiro->refresh();

        $this->assertSame($novo->id, $janeiro->plano_propina_id);
        $this->assertSame(1, $janeiro->ordem);
        $this->assertSame(2_500_000, $janeiro->valor->unidadesMenores());
        $this->assertSame(1_000_000, $janeiro->valor_pago->unidadesMenores());
        $this->assertSame('2027-01-01', $janeiro->periodo_inicio->toDateString());
        $this->assertSame('2027-01-10', $janeiro->data_vencimento->toDateString());
    }

    public function test_reassociar_recusa_paga_e_plano_de_outro_ano(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $paga = $this->comEstado($this->propina($matricula, $plano, 5), EstadoCobranca::PAGA);
        $aberta = $this->propina($matricula, $plano, 6);
        $novo = $this->dividir($plano, '2027-01');
        $deOutroAno = $this->plano($this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31'), 'Outro ano');

        try {
            DB::transaction(fn () => app(ReassociacaoDePropinas::class)->reassociar($paga, $novo, 1));
            $this->fail('Uma Paga nunca se reassocia.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Em Aberto ou Parcialmente Paga', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('mesmo ano lectivo');
        DB::transaction(fn () => app(ReassociacaoDePropinas::class)->reassociar($aberta, $deOutroAno, 2));
    }

    /** @return array<string, array{0: string}> */
    public static function mesesDeEfeitoInvalidos(): array
    {
        return [
            'início do plano' => ['2026-09'],
            'depois do fim' => ['2027-07'],
            'antes do início' => ['2026-08'],
            'fora de intervalo' => ['2027-09'],
            'mês certo, ano errado (antes)' => ['2026-01'],
            'mês certo, ano errado (depois)' => ['2028-01'],
        ];
    }

    #[DataProvider('mesesDeEfeitoInvalidos')]
    public function test_dividir_recusa_um_mes_de_efeito_invalido_sem_tocar_no_plano_antigo(string $mesEfeito): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Mensal');

        try {
            $this->dividir($plano, $mesEfeito);
            $this->fail("O mês de efeito {$mesEfeito} devia ser recusado.");
        } catch (DomainException $e) {
            $this->assertStringContainsString('mês de efeito', $e->getMessage());
        }

        $plano->refresh();
        $this->assertSame([9, 6], [$plano->data_inicio->month, $plano->data_fim->month]);
        $this->assertSame(1, PlanoPropina::query()->count());
    }

    public function test_dividir_recusa_um_mes_que_nao_e_inicio_de_periodo(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);

        $this->expectException(DomainException::class);
        $this->dividir($plano, '2027-01');
    }

    public function test_dividir_com_colisao_de_alvos_recusa_e_reverte(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $plano = $this->plano($ano, 'Mensal', [], [['nivel' => $nivel]]);
        $this->plano($ano, 'Sobreposto', ['mes_inicio' => 1, 'mes_fim' => 3], [['nivel' => $nivel]]);

        try {
            $this->dividir($plano, '2027-01');
            $this->fail('A colisão de alvos devia ser recusada.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('colisão', $e->getMessage());
        }

        $this->assertSame(6, $plano->refresh()->data_fim->month);
        $this->assertSame(2, PlanoPropina::query()->count());
    }

    public function test_dividir_deixa_intacto_o_plano_de_outro_ano_lectivo_com_os_mesmos_alvos(): void
    {
        $nivel = $this->nivel('N1');
        $plano = $this->plano($this->anoLectivo(), 'Mensal', [], [['nivel' => $nivel]]);
        $outro = $this->plano($this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31'), 'Outro ano', [], [['nivel' => $nivel]]);

        $novo = $this->dividir($plano, '2027-01');

        $this->assertNotSame($outro->ano_lectivo_id, $novo->ano_lectivo_id);
        $outro->refresh();
        $this->assertSame([9, 6, 2_500_000], [$outro->data_inicio->month, $outro->data_fim->month, $outro->valor->unidadesMenores()]);
        $this->assertSame($this->chavesDosAlvos($plano), $this->chavesDosAlvos($outro));
    }

    public function test_reassociar_exige_transaccao_e_nao_altera_nada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $janeiro = $this->propina($matricula, $plano, 5);
        $novo = $this->dividir($plano, '2027-01');

        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        try {
            app(ReassociacaoDePropinas::class)->reassociar($janeiro, $novo, 1);
            $this->fail('Devia exigir transacção.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('transacção aberta', $e->getMessage());
        }

        // Nada foi gravado: a instância continua no plano antigo.
        $this->assertSame([$plano->id, 5], [$janeiro->plano_propina_id, $janeiro->ordem]);
    }

    public function test_reassociar_recusa_ordem_menor_que_um_ou_que_nao_corresponde_ao_periodo(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $janeiro = $this->propina($matricula, $plano, 5);
        $novo = $this->dividir($plano, '2027-01');

        foreach ([0, -1] as $ordem) {
            try {
                DB::transaction(fn () => app(ReassociacaoDePropinas::class)->reassociar($janeiro, $novo, $ordem));
                $this->fail("A ordem {$ordem} devia ser recusada.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('maior ou igual a 1', $e->getMessage());
            }
        }

        foreach ([2, 99] as $ordem) {
            try {
                DB::transaction(fn () => app(ReassociacaoDePropinas::class)->reassociar($janeiro, $novo, $ordem));
                $this->fail("A ordem {$ordem} não corresponde ao período de Janeiro.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('não corresponde ao período', $e->getMessage());
            }
        }

        $janeiro->refresh();
        $this->assertSame([$plano->id, 5], [$janeiro->plano_propina_id, $janeiro->ordem]);
    }

    public function test_reassociar_recusa_cancelada_e_anulada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $novo = $this->dividir($plano, '2027-01');

        foreach ([EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA] as $i => $estado) {
            $propina = $this->comEstado($this->propina($matricula, $plano, 5 + $i), $estado);

            try {
                DB::transaction(fn () => app(ReassociacaoDePropinas::class)->reassociar($propina, $novo, 1 + $i));
                $this->fail("Uma propina {$estado->label()} nunca se reassocia.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('Em Aberto ou Parcialmente Paga', $e->getMessage());
            }

            $this->assertSame($plano->id, $propina->fresh()->plano_propina_id);
        }
    }
}
