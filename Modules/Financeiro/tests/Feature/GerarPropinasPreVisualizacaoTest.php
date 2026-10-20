<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

/**
 * O cálculo da geração (spec §7, L8, L9, L19) visto pela pré-visualização, que nunca escreve.
 * Ano 2026/2027 a começar a 01/09/2026; "hoje" = 01/09/2026 salvo indicação.
 */
class GerarPropinasPreVisualizacaoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $extras  argumentos nomeados de PedidoGeracaoPropinasDTO
     */
    private function pedido(Turma $turma, array $extras = []): PedidoGeracaoPropinasDTO
    {
        return new PedidoGeracaoPropinasDTO(...array_merge([
            'ano_lectivo_id' => (int) $turma->ano_lectivo_id,
            'ambito' => AmbitoGeracaoPropinas::TURMA,
            'origem' => OrigemGeracaoPropina::MANUAL,
            'alvo_id' => (int) $turma->id,
        ], $extras));
    }

    /** @return array<string, mixed> */
    private function previa(PedidoGeracaoPropinasDTO $pedido): array
    {
        return app(GerarPropinasService::class)->preVisualizar($pedido)->toArray();
    }

    /** @return array<string, mixed> */
    private function daMatricula(array $resultado, Matricula $matricula): array
    {
        foreach ($resultado['matriculas'] as $linha) {
            if ($linha['matricula_id'] === $matricula->id) {
                return $linha;
            }
        }

        $this->fail("A matrícula {$matricula->id} não está no resultado.");
    }

    /** @return list<string> */
    private function periodos(array $linha): array
    {
        return array_column($linha['criadas'], 'periodo');
    }

    public function test_mensal_set_a_jun_da_dez_propinas_com_o_snapshot_das_regras(): void
    {
        ['turma' => $turma, 'matricula' => $matricula, 'plano' => $plano] = $this->cenarioPropinas();

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame(
            ['Set/2026', 'Out/2026', 'Nov/2026', 'Dez/2026', 'Jan/2027', 'Fev/2027', 'Mar/2027', 'Abr/2027', 'Mai/2027', 'Jun/2027'],
            $this->periodos($linha),
        );
        $this->assertSame([
            'plano_propina_id' => $plano->id,
            'plano' => 'Propina Mensal',
            'ordem' => 1,
            'periodo_inicio' => '2026-09-01',
            'periodo_fim' => '2026-09-30',
            'periodo' => 'Set/2026',
            'meses' => 1,
            'valor' => 2_500_000,
            'data_vencimento' => '2026-09-10',
            'dias_tolerancia' => 5,
            'data_limite' => '2026-09-15',
            'origem_geracao' => OrigemGeracaoPropina::MANUAL->value,
            'origem_geracao_descricao' => 'Manual',
            'motivo_geracao' => null,
            'tardia' => false,
            'regenera_cancelada' => null,
        ], $linha['criadas'][0]);
        $this->assertSame(range(1, 10), array_column($linha['criadas'], 'ordem'));
        $this->assertSame([], $linha['ignoradas']);
        $this->assertSame([], $linha['conflitos']);
        $this->assertSame([], $linha['sem_plano']);
    }

    public function test_trimestral_set_a_jun_da_3_3_3_1_e_o_ultimo_cobra_o_valor_inteiro(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas([
            'periodicidade' => Periodicidade::TRIMESTRAL,
            'intervalo_meses' => 3,
            'valor' => Dinheiro::deUnidadesMenores(7_000_000),
        ]);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame(['Set–Nov/2026', 'Dez/2026–Fev/2027', 'Mar–Mai/2027', 'Jun/2027'], $this->periodos($linha));
        $this->assertSame([3, 3, 3, 1], array_column($linha['criadas'], 'meses'));
        $this->assertSame(['2026-11-30', '2027-02-28', '2027-05-31', '2027-06-30'], array_column($linha['criadas'], 'periodo_fim'));
        $this->assertSame(array_fill(0, 4, 7_000_000), array_column($linha['criadas'], 'valor'));
    }

    public function test_matricula_a_meio_do_ano_so_gera_a_partir_do_mes_da_matricula(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $tardia = $this->matriculaActiva($turma, '2026-11-15');

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $tardia);

        $this->assertCount(8, $linha['criadas']);
        $this->assertSame('Nov/2026', $linha['criadas'][0]['periodo']);
        $this->assertSame(['Set/2026', 'Out/2026'], array_column($linha['ignoradas'], 'periodo'));
        $this->assertStringContainsString('Anterior ao mês da matrícula (15/11/2026)', $linha['ignoradas'][0]['motivo']);
    }

    public function test_trimestral_inclui_o_periodo_em_curso_no_mes_da_matricula(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas(['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $aMeio = $this->matriculaActiva($turma, '2026-10-15');

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $aMeio);

        $this->assertSame(['Set–Nov/2026', 'Dez/2026–Fev/2027', 'Mar–Mai/2027', 'Jun/2027'], $this->periodos($linha));
    }

    public function test_incluir_periodos_anteriores_marca_so_esses_como_retroactivos_com_motivo(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $tardia = $this->matriculaActiva($turma, '2026-11-15');
        $motivo = 'Aluno transferido com propinas de Setembro e Outubro por cobrar.';

        $linha = $this->daMatricula($this->previa($this->pedido($turma, [
            'incluir_periodos_anteriores' => true,
            'motivo_geracao' => $motivo,
        ])), $tardia);

        $this->assertCount(10, $linha['criadas']);
        $this->assertSame(
            [4, 4, 3, 3, 3, 3, 3, 3, 3, 3],
            array_column($linha['criadas'], 'origem_geracao'),
        );
        $this->assertSame($motivo, $linha['criadas'][0]['motivo_geracao']);
        $this->assertNull($linha['criadas'][2]['motivo_geracao']);
        $this->assertSame([], $linha['ignoradas']);
    }

    public function test_so_matriculas_activas_geram_as_outras_ficam_ignoradas_com_o_estado(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $outras = [];

        foreach ([EstadoMatriculaEnum::PENDENTE, EstadoMatriculaEnum::CANCELADA, EstadoMatriculaEnum::CONCLUIDA, EstadoMatriculaEnum::TRANSFERIDA] as $estado) {
            $outras[$estado->label()] = $this->matriculaActiva($turma, '2026-09-01', $estado);
        }

        $resultado = $this->previa($this->pedido($turma));

        foreach ($outras as $rotulo => $matricula) {
            $linha = $this->daMatricula($resultado, $matricula);
            $this->assertSame([], $linha['criadas'], $rotulo);
            $this->assertStringContainsString("A matrícula está {$rotulo}", $linha['ignoradas'][0]['motivo']);
        }

        $this->assertSame(10, $resultado['totais']['criadas']);
    }

    public function test_precedencia_o_plano_da_turma_vence_o_geral(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turmaA = $this->turma($ano, $nivel);
        $turmaB = $this->turma($ano, $nivel);
        $this->plano($ano, 'Geral');
        $this->plano($ano, 'Turma A', ['valor' => Dinheiro::deUnidadesMenores(3_000_000)], [['turma' => $turmaA]]);
        $a = $this->matriculaActiva($turmaA);
        $b = $this->matriculaActiva($turmaB);

        $resultado = $this->previa($this->pedido($turmaA, ['ambito' => AmbitoGeracaoPropinas::NIVEL, 'alvo_id' => $nivel->id]));

        $this->assertSame(array_fill(0, 10, 3_000_000), array_column($this->daMatricula($resultado, $a)['criadas'], 'valor'));
        $this->assertSame(['Turma A'], array_values(array_unique(array_column($this->daMatricula($resultado, $a)['criadas'], 'plano'))));
        $this->assertSame(array_fill(0, 10, 2_500_000), array_column($this->daMatricula($resultado, $b)['criadas'], 'valor'));
    }

    public function test_fases_de_preco_resolvem_mes_a_mes(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $this->plano($ano, 'Set–Dez', ['mes_inicio' => 9, 'mes_fim' => 12, 'valor' => Dinheiro::deUnidadesMenores(1_000_000)]);
        $this->plano($ano, 'Jan–Jun', ['mes_inicio' => 1, 'mes_fim' => 6, 'valor' => Dinheiro::deUnidadesMenores(1_200_000)]);
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame([...array_fill(0, 4, 1_000_000), ...array_fill(0, 6, 1_200_000)], array_column($linha['criadas'], 'valor'));
        $this->assertSame([1, 2, 3, 4, 1, 2, 3, 4, 5, 6], array_column($linha['criadas'], 'ordem'));
        $this->assertSame('Jan–Jun', $linha['criadas'][4]['plano']);
    }

    public function test_planos_empatados_dao_conflito_e_nada_e_criado(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $this->plano($ano, 'Geral 1');
        $this->plano($ano, 'Geral 2');
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame([], $linha['criadas']);
        $this->assertCount(10, $linha['conflitos']);
        $this->assertStringContainsString('Set/2026', $linha['conflitos'][0]);
        $this->assertStringContainsString('«Geral 1»', $linha['conflitos'][0]);
        $this->assertStringContainsString('«Geral 2»', $linha['conflitos'][0]);
    }

    public function test_periodo_com_meses_de_outro_plano_e_conflito_e_os_restantes_geram(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $this->plano($ano, 'Outubro da turma', ['mes_inicio' => 10, 'mes_fim' => 10, 'valor' => Dinheiro::deUnidadesMenores(900_000)], [['turma' => $turma]]);
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame(['Out/2026', 'Dez/2026–Fev/2027', 'Mar–Mai/2027', 'Jun/2027'], $this->periodos($linha));
        $this->assertCount(1, $linha['conflitos']);
        $this->assertStringContainsString('Set–Nov/2026', $linha['conflitos'][0]);
        $this->assertStringContainsString('«Trimestral»', $linha['conflitos'][0]);
    }

    public function test_meses_sem_plano_aplicavel_sao_reportados(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $turma = $this->turma($ano, $n1);
        $this->plano($ano, 'Geral');
        $this->plano($ano, 'Julho do N2', ['mes_inicio' => 7, 'mes_fim' => 7], [['nivel' => $this->nivel('N2')]]);
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertCount(10, $linha['criadas']);
        $this->assertSame(['Jul/2027'], $linha['sem_plano']);
    }

    public function test_plano_inactivo_nao_gera(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas(['estado' => Estado::INATIVO->value]);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame([], $linha['criadas']);
        $this->assertSame([], $linha['conflitos']);
    }

    public function test_com_um_plano_escolhido_os_periodos_de_outro_plano_ficam_ignorados(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $setDez = $this->plano($ano, 'Set–Dez', ['mes_inicio' => 9, 'mes_fim' => 12]);
        $this->plano($ano, 'Jan–Jun', ['mes_inicio' => 1, 'mes_fim' => 6]);
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma, ['plano_propina_id' => $setDez->id])), $matricula);

        $this->assertSame(['Set/2026', 'Out/2026', 'Nov/2026', 'Dez/2026'], $this->periodos($linha));
        $this->assertCount(6, $linha['ignoradas']);
        $this->assertSame('Aplica-se outro plano a este período: «Jan–Jun».', $linha['ignoradas'][0]['motivo']);
    }

    public function test_ambito_plano_so_inclui_as_turmas_a_que_o_plano_se_aplica(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $turma1 = $this->turma($ano, $n1);
        $turma2 = $this->turma($ano, $this->nivel('N2'));
        $this->plano($ano, 'Geral');
        $doN1 = $this->plano($ano, 'Do N1', ['valor' => Dinheiro::deUnidadesMenores(2_800_000)], [['nivel' => $n1]]);
        $m1 = $this->matriculaActiva($turma1);
        $this->matriculaActiva($turma2);

        $resultado = $this->previa($this->pedido($turma1, [
            'ambito' => AmbitoGeracaoPropinas::PLANO,
            'alvo_id' => null,
            'plano_propina_id' => $doN1->id,
        ]));

        $this->assertSame([$m1->id], array_column($resultado['matriculas'], 'matricula_id'));
        $this->assertSame(10, $resultado['totais']['criadas']);
    }

    public function test_ambito_matricula_pelo_numero_de_registo(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->matriculaActiva($turma);

        $resultado = $this->previa($this->pedido($turma, [
            'ambito' => AmbitoGeracaoPropinas::MATRICULA,
            'alvo_id' => null,
            'matricula_numero' => $matricula->numero_registo_matricula,
        ]));

        $this->assertSame([$matricula->id], array_column($resultado['matriculas'], 'matricula_id'));
    }

    public function test_a_pre_visualizacao_nao_escreve_nada(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        // Nos testes a configuração monetária nasce na primeira leitura (em produção é provisionada).
        app(MoedaDoTenant::class)->atual();
        $escritas = [];
        DB::listen(function (QueryExecuted $consulta) use (&$escritas) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $consulta->sql) === 1) {
                $escritas[] = $consulta->sql;
            }
        });

        $resultado = $this->previa($this->pedido($turma));

        $this->assertSame(10, $resultado['totais']['criadas']);
        $this->assertTrue($resultado['previsualizacao']);
        $this->assertSame([], $escritas);
        $this->assertSame(0, Propina::query()->count());
    }

    public function test_a_geracao_tardia_usa_o_dia_da_escola_e_nao_o_de_utc(): void
    {
        // 10/10/2026 23:30 UTC = 11/10/2026 00:30 em Luanda: o vencimento de Outubro (dia 10) já passou na escola.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 23:30:00', 'UTC'));
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);
        $tardias = array_column($linha['criadas'], 'tardia', 'periodo');

        $this->assertTrue($tardias['Set/2026']);
        $this->assertTrue($tardias['Out/2026']);
        $this->assertFalse($tardias['Nov/2026']);
    }

    public function test_turma_de_outro_tenant_nao_traz_matriculas(): void
    {
        $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $turmaDoOutro = $this->noTenant($outro, fn () => $this->cenarioPropinas()['turma']);

        $resultado = $this->previa($this->pedido($turmaDoOutro));

        $this->assertSame([], $resultado['matriculas']);
        $this->assertSame(0, $resultado['totais']['criadas']);
    }

    public function test_uma_unica_matricula_mostra_total_e_multa_estimada_com_regra_por_linha(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        $this->multaActiva([[10, TipoMulta::PERCENTAGEM, 1000]]);
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();

        $resultado = $this->previa($this->pedido($turma));
        $linha = $this->daMatricula($resultado, $matricula);

        $this->assertSame(25_000_000, $linha['total_capital']);
        $this->assertSame(250_000, $linha['total_multa']);
        $this->assertSame(250_000, $linha['criadas'][0]['multa']);
        $this->assertSame('10% · escalão 1 (após 10 dias)', $linha['criadas'][0]['multa_regra']);
        $this->assertArrayNotHasKey('multa', $linha['criadas'][1]);
        $this->assertSame(25_000_000, $resultado['totais']['capital']);
        $this->assertSame(250_000, $resultado['totais']['multas']);
        $this->assertTrue($resultado['inclui_multas_estimadas']);
    }

    public function test_sem_multa_activa_nao_estima_multas_mas_mostra_o_capital(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();

        $resultado = $this->previa($this->pedido($turma));
        $linha = $this->daMatricula($resultado, $matricula);

        $this->assertSame(25_000_000, $linha['total_capital']);
        $this->assertSame(0, $linha['total_multa']);
        $this->assertArrayNotHasKey('multa', $linha['criadas'][0]);
        $this->assertSame(0, $resultado['totais']['multas']);
        $this->assertFalse($resultado['inclui_multas_estimadas']);
    }

    public function test_varias_matriculas_nao_calculam_multa_por_linha_e_assinalam_inclusao(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        $this->multaActiva([[10, TipoMulta::PERCENTAGEM, 1000]]);
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->matriculaActiva($turma);

        $resultado = $this->previa($this->pedido($turma));
        $linha = $this->daMatricula($resultado, $matricula);

        $this->assertCount(2, $resultado['matriculas']);
        $this->assertArrayNotHasKey('multa', $linha['criadas'][0]);
        $this->assertNull($linha['total_multa']);
        $this->assertSame(0, $resultado['totais']['multas']);
        $this->assertSame(50_000_000, $resultado['totais']['capital']);
        $this->assertTrue($resultado['inclui_multas_estimadas']);
    }
}
