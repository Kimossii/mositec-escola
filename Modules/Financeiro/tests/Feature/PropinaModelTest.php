<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PropinaModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function umaPropina(array $atributos = []): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano, 1, $atributos);
    }

    public function test_a_tabela_tem_os_indices_previstos(): void
    {
        $indices = collect(Schema::getIndexes('propinas'))->keyBy('name');

        $esperados = [
            'propinas_plano_ordem_activo_unique' => [true, ['tenant_id', 'matricula_id', 'plano_propina_id', 'ordem']],
            'propinas_periodo_activo_unique' => [true, ['tenant_id', 'matricula_id', 'periodo_inicio']],
            'propinas_matricula_periodo_index' => [false, ['tenant_id', 'matricula_id', 'periodo_inicio']],
            'propinas_estado_vencimento_index' => [false, ['tenant_id', 'estado', 'data_vencimento']],
            'propinas_estado_limite_index' => [false, ['tenant_id', 'estado', 'data_limite']],
            'propinas_plano_estado_index' => [false, ['tenant_id', 'plano_propina_id', 'estado']],
            'propinas_ano_estado_index' => [false, ['tenant_id', 'ano_lectivo_id', 'estado']],
        ];

        foreach ($esperados as $nome => [$unico, $colunas]) {
            $this->assertTrue($indices->has($nome), "Falta o índice {$nome}.");
            $this->assertSame($unico, $indices[$nome]['unique'], $nome);
            $this->assertSame($colunas, $indices[$nome]['columns'], $nome);
        }
    }

    public function test_criar_copia_o_valor_original_calcula_o_limite_e_sincroniza_as_descricoes(): void
    {
        $propina = $this->umaPropina()->refresh();

        $this->assertSame(2_500_000, $propina->valor->unidadesMenores());
        $this->assertSame(2_500_000, $propina->valor_original->unidadesMenores());
        $this->assertSame(0, $propina->valor_pago->unidadesMenores());
        $this->assertSame('2026-09-01', $propina->periodo_inicio->toDateString());
        $this->assertSame('2026-09-30', $propina->periodo_fim->toDateString());
        $this->assertSame('2026-09-10', $propina->data_vencimento->toDateString());
        $this->assertSame('2026-09-15', $propina->data_limite->toDateString());
        $this->assertSame('2026-09-15', $propina->getRawOriginal('data_limite')); // sem hora, também em SQLite
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->estado);
        $this->assertSame('Em Aberto', $propina->estado_descricao);
        $this->assertSame(OrigemGeracaoPropina::MANUAL, $propina->origem_geracao);
        $this->assertSame('Manual', $propina->origem_geracao_descricao);
        $this->assertSame('AOA', $propina->moeda);
        $this->assertNull($propina->cambio_usd);
        $this->assertNull($propina->taxaCambio());
        $this->assertSame($this->tenant->id, (int) $propina->tenant_id);
        $this->assertSame(2_500_000, $propina->saldo()->unidadesMenores());
    }

    public function test_campos_protegidos_nao_sao_preenchiveis_e_o_valor_original_e_sempre_o_valor(): void
    {
        foreach (['tenant_id', 'valor_original', 'valor_pago', 'estado', 'estado_descricao', 'capital_liquidado_em', 'data_limite',
            'origem_geracao_descricao', 'motivo_cancelamento', 'cancelado_por', 'cancelado_em', 'motivo_anulacao', 'anulado_por',
            'anulado_em', 'criado_por', 'editado_por'] as $campo) {
            $this->assertFalse((new Propina())->isFillable($campo), $campo);
        }

        $propina = $this->umaPropina(['valor_original' => 1, 'valor_pago' => 999, 'estado' => EstadoCobranca::PAGA, 'data_limite' => '2030-01-01']);

        $this->assertSame(2_500_000, $propina->valor_original->unidadesMenores());
        $this->assertSame(0, $propina->valor_pago->unidadesMenores());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->estado);
        $this->assertSame('2026-09-15', $propina->data_limite->toDateString());
    }

    public function test_valor_original_imposto_por_force_fill_e_reposto_ao_criar(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        $propina = (new Propina())->forceFill([
            'matricula_id' => $matricula->id, 'plano_propina_id' => $plano->id, 'ano_lectivo_id' => $plano->ano_lectivo_id,
            'ordem' => 1, 'periodo_inicio' => '2026-09-01', 'periodo_fim' => '2026-09-30', 'valor' => 2_500_000,
            'valor_original' => 1, 'moeda' => 'AOA', 'data_vencimento' => '2026-09-10', 'dias_tolerancia' => 5,
            'origem_geracao' => OrigemGeracaoPropina::MANUAL,
        ]);
        $propina->save();

        $this->assertSame(2_500_000, $propina->refresh()->valor_original->unidadesMenores());
    }

    public function test_a_lista_de_imutaveis_e_a_do_snapshot(): void
    {
        $this->assertSame([
            'matricula_id', 'ano_lectivo_id', 'periodo_inicio', 'periodo_fim', 'valor_original', 'moeda', 'cambio_usd',
            'data_vencimento', 'dias_tolerancia', 'data_limite', 'origem_geracao', 'motivo_geracao',
        ], Propina::IMUTAVEIS);
    }

    #[DataProvider('alteracoesImutaveis')]
    public function test_campos_do_snapshot_sao_imutaveis(string $campo, mixed $novo): void
    {
        $propina = $this->umaPropina();
        $antes = $propina->fresh()->getAttributes();

        try {
            $propina->forceFill([$campo => $novo])->save();
            $this->fail("{$campo} devia ser imutável.");
        } catch (LogicException $e) {
            $this->assertStringContainsString("Campos imutáveis de uma propina não podem mudar: {$campo}.", $e->getMessage());
        }

        $this->assertSame($antes, $propina->fresh()->getAttributes());
    }

    public static function alteracoesImutaveis(): array
    {
        return [
            'valor_original' => ['valor_original', 1],
            'matricula_id' => ['matricula_id', 999],
            'ano_lectivo_id' => ['ano_lectivo_id', 999],
            'periodo_inicio' => ['periodo_inicio', '2026-08-01'],
            'periodo_fim' => ['periodo_fim', '2026-10-31'],
            'moeda' => ['moeda', 'USD'],
            'cambio_usd' => ['cambio_usd', 910_000_000],
            'data_vencimento' => ['data_vencimento', '2026-09-12'],
            'dias_tolerancia' => ['dias_tolerancia', 9],
            'data_limite' => ['data_limite', '2026-09-30'],
            'origem_geracao' => ['origem_geracao', OrigemGeracaoPropina::COMANDO],
            'motivo_geracao' => ['motivo_geracao', 'Outro motivo'],
        ];
    }

    public function test_valor_e_plano_podem_mudar_sem_tocar_no_valor_original(): void
    {
        $propina = $this->umaPropina();

        $propina->forceFill(['valor' => Dinheiro::deUnidadesMenores(3_000_000)])->save();

        $this->assertSame(3_000_000, $propina->refresh()->valor->unidadesMenores());
        $this->assertSame(2_500_000, $propina->valor_original->unidadesMenores());
    }

    public function test_uma_propina_nunca_e_eliminada(): void
    {
        $propina = $this->umaPropina();

        try {
            $propina->delete();
            $this->fail('A eliminação devia ser recusada.');
        } catch (LogicException $e) {
            $this->assertSame('Uma propina nunca é eliminada: cancele-a ou anule-a.', $e->getMessage());
        }

        $this->assertNotNull(Propina::query()->find($propina->id));
    }

    #[DataProvider('derivados')]
    public function test_estados_derivados_nunca_se_gravam(EstadoCobranca $estado): void
    {
        $propina = $this->umaPropina();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Pendente e Em Atraso são estados derivados e nunca se gravam.');

        $propina->forceFill(['estado' => $estado])->save();
    }

    public static function derivados(): array
    {
        return ['pendente' => [EstadoCobranca::PENDENTE], 'em atraso' => [EstadoCobranca::EM_ATRASO]];
    }

    public function test_as_descricoes_acompanham_cada_estado_persistido(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $estados = [EstadoCobranca::PARCIALMENTE_PAGA, EstadoCobranca::PAGA, EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA];

        foreach ($estados as $indice => $estado) {
            $propina = $this->comEstado($this->propina($matricula, $plano, $indice + 1), $estado);

            $this->assertSame($estado, $propina->estado);
            $this->assertSame($estado->label(), $propina->estado_descricao);
        }

        $this->assertPropinasCoerentes();
    }

    #[DataProvider('incoerencias')]
    public function test_estado_e_montantes_incoerentes_sao_recusados_tambem_em_sqlite(array $atributos, string $mensagem): void
    {
        $propina = $this->umaPropina();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($mensagem);

        $propina->forceFill($atributos)->save();
    }

    public static function incoerencias(): array
    {
        return [
            'pago acima do valor' => [['valor_pago' => 2_500_001, 'estado' => EstadoCobranca::PAGA, 'capital_liquidado_em' => '2026-09-05'], 'O valor pago não pode exceder o valor da propina.'],
            'paga sem pagamento' => [['estado' => EstadoCobranca::PAGA], 'O estado Paga não é coerente com o valor pago.'],
            'aberta com pagamento' => [['valor_pago' => 1], 'O estado Em Aberto não é coerente com o valor pago.'],
            'liquidação com saldo' => [['capital_liquidado_em' => '2026-09-05'], 'A data de liquidação do capital só existe quando a propina está totalmente paga.'],
            'cancelada sem data' => [['estado' => EstadoCobranca::CANCELADA], 'Uma propina cancelada exige a data de cancelamento, e só ela a tem.'],
            'data de anulação sem anular' => [['anulado_em' => '2026-09-05 10:00:00'], 'Uma propina anulada exige a data de anulação, e só ela a tem.'],
            'valor zero' => [['valor' => 0], 'O valor da propina tem de ser maior que zero.'],
            'cancelada sem motivo' => [['estado' => EstadoCobranca::CANCELADA, 'cancelado_em' => '2026-09-05 10:00:00'], 'Uma propina cancelada exige o motivo do cancelamento.'],
            'cancelada com motivo em branco' => [['estado' => EstadoCobranca::CANCELADA, 'cancelado_em' => '2026-09-05 10:00:00', 'motivo_cancelamento' => '  '], 'Uma propina cancelada exige o motivo do cancelamento.'],
            'anulada sem motivo' => [['estado' => EstadoCobranca::ANULADA, 'anulado_em' => '2026-09-05 10:00:00'], 'Uma propina anulada exige o motivo da anulação.'],
            'autor do cancelamento sem cancelar' => [['cancelado_por' => 1], 'Só uma propina cancelada tem o autor do cancelamento.'],
            'autor da anulação sem anular' => [['anulado_por' => 1], 'Só uma propina anulada tem o autor da anulação.'],
        ];
    }

    public function test_mudar_o_plano_exige_o_mesmo_ano_lectivo(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $planoOutroAno = $this->plano($outroAno, 'Propina 2027');
        $mesmoAno = $this->plano($ano, 'Outro Plano');

        try {
            $propina->forceFill(['plano_propina_id' => $planoOutroAno->id])->save();
            $this->fail('Devia recusar o plano de outro ano lectivo.');
        } catch (LogicException $e) {
            $this->assertSame('O plano de uma propina só pode mudar para um plano do mesmo ano lectivo.', $e->getMessage());
        }

        $propina->refresh()->forceFill(['plano_propina_id' => $mesmoAno->id])->save();
        $this->assertSame($mesmoAno->id, $propina->refresh()->plano_propina_id);
    }

    #[DataProvider('criacoesInvalidas')]
    public function test_invariantes_de_criacao(array $atributos, string $mensagem): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($mensagem);

        $this->umaPropina($atributos);
    }

    public static function criacoesInvalidas(): array
    {
        return [
            'sem origem' => [['origem_geracao' => null], 'A origem da geração da propina é obrigatória.'],
            'retroactiva sem motivo' => [['origem_geracao' => OrigemGeracaoPropina::RETROACTIVA], 'Uma propina retroactiva exige o motivo da geração.'],
            'retroactiva com motivo em branco' => [['origem_geracao' => OrigemGeracaoPropina::RETROACTIVA, 'motivo_geracao' => '   '], 'Uma propina retroactiva exige o motivo da geração.'],
            'ordem zero' => [['ordem' => 0], 'A ordem do período tem de ser maior ou igual a 1.'],
            'fim antes do início' => [['periodo_fim' => '2026-08-31'], 'O período da propina é inválido: o fim não pode ser anterior ao início.'],
            'vencimento antes do início' => [['data_vencimento' => '2026-08-31'], 'O vencimento não pode ser anterior ao início do período.'],
            'tolerância negativa' => [['dias_tolerancia' => -1], 'A tolerância (dias) não pode ser negativa.'],
            'moeda desconhecida' => [['moeda' => 'XXX'], 'Moeda desconhecida na propina.'],
            'câmbio zero' => [['cambio_usd' => 0], 'O câmbio da propina tem de ser maior que zero (ou nulo).'],
            'valor zero' => [['valor' => 0], 'O valor da propina tem de ser maior que zero.'],
        ];
    }

    public function test_retroactiva_com_motivo_e_aceite(): void
    {
        $propina = $this->umaPropina(['origem_geracao' => OrigemGeracaoPropina::RETROACTIVA, 'motivo_geracao' => 'Aluno integrado em Novembro com meses em atraso.']);

        $this->assertSame('Retroactiva', $propina->refresh()->origem_geracao_descricao);
    }

    public function test_plano_e_matricula_de_anos_lectivos_diferentes_sao_recusados(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $planoDoOutroAno = $this->plano($outroAno, 'Propina 2027');

        try {
            $this->propina($matricula, $planoDoOutroAno);
            $this->fail('Devia recusar o plano de outro ano lectivo.');
        } catch (LogicException $e) {
            $this->assertSame('A propina, a matrícula e o plano têm de ser do mesmo ano lectivo.', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A propina, a matrícula e o plano têm de ser do mesmo ano lectivo.');

        $this->propina($matricula, $planoDoOutroAno, 1, ['ano_lectivo_id' => $matricula->ano_lectivo_id]);
    }

    public function test_duas_activas_no_mesmo_inicio_de_periodo_falham_mesmo_com_planos_diferentes(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $outroPlano = $this->plano($ano, 'Outro Plano');
        $this->propina($matricula, $plano);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/propinas_periodo_activo_unique|propinas\.periodo_inicio/'); // PostgreSQL | SQLite

        $this->propina($matricula, $outroPlano);
    }

    public function test_duas_activas_com_o_mesmo_plano_e_ordem_falham_mesmo_com_inicio_diferente(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/propinas_plano_ordem_activo_unique|propinas\.plano_propina_id/'); // PostgreSQL | SQLite

        $this->propina($matricula, $plano, 1, ['periodo_inicio' => '2026-10-01', 'periodo_fim' => '2026-10-31', 'data_vencimento' => '2026-10-10']);
    }

    public function test_depois_de_cancelar_ou_anular_o_mesmo_periodo_pode_ser_regerado(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::CANCELADA);
        $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::ANULADA);
        $activa = $this->propina($matricula, $plano);

        $this->assertSame(3, Propina::query()->where('matricula_id', $matricula->id)->count());
        $this->assertSame([$activa->id], Propina::query()->activas()->pluck('id')->all());
        $this->assertPropinasCoerentes();
    }

    public function test_relacoes_saldo_e_estado_resolvido(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);

        $this->assertSame($matricula->id, $propina->matricula->id);
        $this->assertSame($plano->id, $propina->plano->id);
        $this->assertSame($ano->id, $propina->anoLectivo->id);
        $this->assertSame([$propina->id], $plano->propinas()->pluck('id')->all());
        $this->assertSame(1_500_000, $propina->saldo()->unidadesMenores());
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $propina->estadoResolvido(CarbonImmutable::parse('2026-09-15')));
        $this->assertSame(EstadoCobranca::EM_ATRASO, $propina->estadoResolvido(CarbonImmutable::parse('2026-09-16')));
    }
}
