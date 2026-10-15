<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlteracaoPrecoPreVisualizacaoTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private const APLICAR = ModoAlteracaoPreco::APLICAR_GERADAS;
    private const MANTER = ModoAlteracaoPreco::MANTER_GERADAS;

    /** @return array{0: PlanoPropina, 1: array<string, Propina>} plano "Propina Mensal" (Set→Jun, 25.000,00) e as 10 propinas de cenarioDeEstados */
    private function estados(): array
    {
        $estados = $this->cenarioDeEstados();

        return [$estados['set_aberta']->plano()->firstOrFail(), $estados];
    }

    public function test_plano_sem_propinas_recusa_e_indica_editar(): void
    {
        ['plano' => $plano] = $this->cenarioPropinas();

        $this->assertRecusa('valor', 'altere o valor em «Editar»', fn () => $this->previa($plano, '2027-01', 3_000_000, self::APLICAR));
    }

    public function test_valor_igual_ao_actual_recusa(): void
    {
        [$plano] = $this->estados();

        $this->assertRecusa('valor', 'diferente do valor actual', fn () => $this->previa($plano, '2027-01', 2_500_000, self::APLICAR));
    }

    public function test_mes_de_efeito_tem_de_ser_inicio_de_um_periodo_do_plano_no_seu_ano(): void
    {
        ['ano' => $ano, 'turma' => $turma] = $this->cenarioPropinas();
        $trimestral = $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $this->propina($this->matriculaActiva($turma), $trimestral, 1);

        foreach (['2027-01', '2025-12', '2027-07', '2027-09'] as $mes) {
            $this->assertRecusa('mes_efeito', 'tem de ser o início de um período deste plano', fn () => $this->previa($trimestral, $mes, 3_000_000, self::APLICAR));
        }

        $this->assertFalse($this->previa($trimestral, '2026-12', 3_000_000, self::APLICAR)->inLoco);
    }

    public function test_aplicar_com_divisao_lista_afectadas_inalteradas_planos_e_totais(): void
    {
        [$plano, $e] = $this->estados();

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);

        $this->assertFalse($previa->inLoco);
        $this->assertSame('Propina Mensal (desde 02/2027)', $previa->nomePlanoNovo);
        $this->assertSame([$e['fev_aberta']->id, $e['mar_parcial']->id, $e['mai_aberta']->id, $e['jun_aberta']->id], array_column($previa->afectadas, 'propina_id'));
        $this->assertSame([6, 7, 9, 10], array_column($previa->afectadas, 'ordem_antes'));
        $this->assertSame([1, 2, 4, 5], array_column($previa->afectadas, 'ordem_depois'));
        $this->assertSame([3_000_000, 3_000_000, 3_000_000, 3_000_000], array_column($previa->afectadas, 'valor_depois'));
        $this->assertSame([3_000_000, 1_000_000, 3_000_000, 3_000_000], array_column($previa->afectadas, 'saldo_depois'));
        $this->assertSame([$e['abr_paga']->id], array_column($previa->inalteradas, 'propina_id'));
        $this->assertStringContainsString('Paga', $previa->inalteradas[0]['motivo']);
        $this->assertSame([
            'afectadas' => 4, 'inalteradas' => 1, 'reassociadas_sem_ajuste' => 0, 'anteriores_ao_mes_efeito' => 5,
            'valor_antes' => 10_000_000, 'valor_depois' => 12_000_000, 'recebido' => 2_000_000,
            'saldo_antes' => 8_000_000, 'saldo_depois' => 10_000_000, 'excesso' => 0,
        ], $previa->totais);
        $this->assertSame(['antigo', 'novo'], array_column($previa->planos, 'papel'));
        $this->assertSame([9, 1], [$previa->planos[0]['mes_inicio'], $previa->planos[0]['mes_fim']]);
        $this->assertCount(5, $previa->planos[0]['periodos']);
        $this->assertSame([2, 6, 3_000_000], [$previa->planos[1]['mes_inicio'], $previa->planos[1]['mes_fim'], $previa->planos[1]['valor']]);
        $this->assertSame('Fev/2027', $previa->planos[1]['periodos'][0]['periodo']);
        $this->assertSame(['id' => $plano->ano_lectivo_id, 'nome' => '2026/2027'], $previa->anoLectivo);
        $this->assertSame(0, $previa->propinasDeOutrosAnos);
        $this->assertNull($previa->avisoValoresDiferentes);
        $this->assertFalse($previa->exigeConfirmacaoValoresDiferentes());
        $this->assertSame(64, strlen($previa->impressaoDigital));
    }

    public function test_descida_abaixo_do_pago_e_assinalada_e_fica_no_pago(): void
    {
        [$plano, $e] = $this->estados();

        $previa = $this->previa($plano, '2027-02', 1_500_000, self::APLICAR);
        $marco = collect($previa->afectadas)->firstWhere('propina_id', $e['mar_parcial']->id);

        $this->assertSame(2_000_000, $marco['valor_depois']);
        $this->assertSame(0, $marco['saldo_depois']);
        $this->assertSame(500_000, $marco['excesso']);
        $this->assertTrue($marco['passa_a_paga']);
        $this->assertSame(500_000, $previa->totais['excesso']);
        $this->assertFalse(collect($previa->afectadas)->firstWhere('propina_id', $e['fev_aberta']->id)['passa_a_paga']);
    }

    public function test_in_loco_quando_o_mes_de_efeito_e_o_inicio_do_plano(): void
    {
        [$plano, $e] = $this->estados();

        $previa = $this->previa($plano, '2026-09', 3_000_000, self::APLICAR);

        $this->assertTrue($previa->inLoco);
        $this->assertNull($previa->nomePlanoNovo);
        $this->assertSame(['mesmo'], array_column($previa->planos, 'papel'));
        $this->assertSame(
            [$e['set_aberta']->id, $e['out_parcial']->id, $e['fev_aberta']->id, $e['mar_parcial']->id, $e['mai_aberta']->id, $e['jun_aberta']->id],
            array_column($previa->afectadas, 'propina_id'),
        );
        $this->assertSame(array_column($previa->afectadas, 'ordem_antes'), array_column($previa->afectadas, 'ordem_depois'));
        $this->assertSame(0, $previa->totais['anteriores_ao_mes_efeito']);
    }

    public function test_manter_nao_afecta_nenhuma_propina_e_explica_porque(): void
    {
        [$plano, $e] = $this->estados();

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame([], $previa->afectadas);
        $this->assertSame(0, $previa->totais['afectadas']);
        $this->assertCount(5, $previa->inalteradas);
        $motivos = array_column($previa->inalteradas, 'motivo', 'propina_id');
        $this->assertStringContainsString('Paga', $motivos[$e['abr_paga']->id]);
        $this->assertStringContainsString('Manter as já geradas', $motivos[$e['fev_aberta']->id]);
        $this->assertSame(['antigo', 'novo'], array_column($previa->planos, 'papel'));
    }

    public function test_a_pre_visualizacao_nunca_escreve(): void
    {
        [$plano] = $this->estados();
        $antes = $this->fotografia();

        $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);
        $this->previa($plano, '2027-02', 1_500_000, self::MANTER);
        $this->previa($plano, '2026-09', 3_000_000, self::APLICAR);

        $this->assertSame($antes, $this->fotografia());
    }

    public function test_ancora_do_calendario_l6_recusa_um_plano_novo_que_cairia_noutro_ano_civil(): void
    {
        ['ano' => $ano, 'turma' => $turma] = $this->cenarioPropinas();
        // Out → Set num ano que começa em Setembro: um plano novo "Set → Set" seria ancorado em Set/2026, não Set/2027.
        $plano = $this->plano($ano, 'Out a Set', ['mes_inicio' => 10, 'mes_fim' => 9]);
        $this->propina($this->matriculaActiva($turma), $plano, 1);

        $this->assertRecusa('mes_efeito', 'calendário do plano novo', fn () => $this->previa($plano, '2027-09', 3_000_000, self::MANTER));
        $this->assertFalse($this->previa($plano, '2027-08', 3_000_000, self::MANTER)->inLoco);
    }

    public function test_candidata_sem_periodo_no_plano_novo_recusa(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 6);
        $junho = $this->propina($matricula, $plano, 10);
        // Como depois de um MANTER anterior a partir de Mai: Junho ficou neste plano, fora do seu calendário.
        $plano->update(['mes_fim' => 4]);

        $this->assertRecusa('mes_efeito', 'não corresponde a nenhum período do plano novo', fn () => $this->previa($plano, '2027-02', 3_000_000, self::APLICAR));

        $inLoco = $this->previa($plano, '2026-09', 3_000_000, self::APLICAR);
        $this->assertSame(10, collect($inLoco->afectadas)->firstWhere('propina_id', $junho->id)['ordem_depois']);
    }

    public function test_nome_do_plano_novo_e_unico_no_ano_e_cabe_em_100_caracteres(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 6);
        $this->plano($ano, 'Propina Mensal (desde 02/2027)', ['estado' => Estado::INATIVO->value, 'mes_inicio' => 7, 'mes_fim' => 8]);
        $servico = app(AlteracaoPrecoPlanoService::class);

        $this->assertSame('Propina Mensal (desde 02/2027) 2', $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->nomePlanoNovo);

        $plano->update(['nome' => str_repeat('A', 100)]);
        $longo = $servico->nomeDoPlanoNovo($plano->fresh(), $this->pedidoAlteracao('2027-02', 1, self::APLICAR)->mesEfeito);
        $this->assertSame(100, mb_strlen($longo));
        $this->assertStringEndsWith(' (desde 02/2027)', $longo);

        $plano->update(['nome' => 'Mensal (desde 01/2027)']);
        $this->assertSame('Mensal (desde 03/2027)', $servico->nomeDoPlanoNovo($plano->fresh(), $this->pedidoAlteracao('2027-03', 1, self::APLICAR)->mesEfeito));
    }

    public function test_impressao_digital_estavel_e_sensivel_a_parametros_e_a_pagamentos(): void
    {
        [$plano, $e] = $this->estados();
        $base = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;

        $this->assertSame($base, $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital);
        $this->assertNotSame($base, $this->previa($plano, '2027-02', 3_100_000, self::APLICAR)->impressaoDigital);
        $this->assertNotSame($base, $this->previa($plano, '2027-02', 3_000_000, self::MANTER)->impressaoDigital);
        $this->assertNotSame($base, $this->previa($plano, '2027-03', 3_000_000, self::APLICAR)->impressaoDigital);

        // Um pagamento entre a pré-visualização e a confirmação.
        $this->comEstado($e['fev_aberta'], EstadoCobranca::PARCIALMENTE_PAGA, 100_000);
        $this->assertNotSame($base, $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital);
    }

    public function test_to_array_tem_o_contrato_json(): void
    {
        [$plano] = $this->estados();

        $json = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->toArray();

        $this->assertSame([
            'modo', 'modo_descricao', 'mes_efeito', 'mes_efeito_rotulo', 'in_loco', 'ano_lectivo', 'garantia_outros_anos',
            'propinas_de_outros_anos', 'valor_anterior', 'valor_novo', 'nome_plano_novo', 'planos', 'afectadas', 'inalteradas', 'reassociadas_sem_ajuste',
            'totais', 'aviso_valores_diferentes', 'exige_confirmacao_valores_diferentes', 'impressao_digital',
        ], array_keys($json));
        $this->assertSame('2027-02', $json['mes_efeito']);
        $this->assertSame('Fev/2027', $json['mes_efeito_rotulo']);
        $this->assertSame('Nenhuma propina de outros anos lectivos será alterada.', $json['garantia_outros_anos']);
    }

    public function test_manter_avisa_com_numeros_reais_e_exige_confirmacao(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $comPropinas] = $this->cenarioPropinas();
        $outraTurma = $this->turma($ano, $this->nivel('N-OUTRA'));

        foreach (range(6, 10) as $ordem) {
            $this->propina($comPropinas, $plano, $ordem);
        }

        $soFevereiro = $this->matriculaActiva($turma);
        $this->propina($soFevereiro, $plano, 6);
        $this->matriculaActiva($turma);                                                  // sem propinas: pagará o novo
        $this->matriculaActiva($outraTurma);                                             // sem propinas: pagará o novo
        $this->matriculaActiva($turma, '2026-09-01', EstadoMatriculaEnum::PENDENTE);     // não Activa: não conta

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame(2, $previa->avisoValoresDiferentes['matriculas_valor_antigo']);
        $this->assertSame(2, $previa->avisoValoresDiferentes['matriculas_valor_novo']);
        $this->assertSame($turma->nome, $previa->avisoValoresDiferentes['turma_exemplo']);
        $this->assertStringContainsString('valores diferentes', $previa->avisoValoresDiferentes['mensagem']);
        $this->assertTrue($previa->exigeConfirmacaoValoresDiferentes());
        $this->assertTrue($previa->toArray()['exige_confirmacao_valores_diferentes']);
    }

    public function test_aplicar_nao_tem_aviso_de_valores_diferentes(): void
    {
        [$plano] = $this->estados();

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);

        $this->assertNull($previa->avisoValoresDiferentes);
        $this->assertFalse($previa->exigeConfirmacaoValoresDiferentes());
    }

    public function test_manter_sem_propinas_geradas_a_partir_do_mes_de_efeito_nao_exige_confirmacao(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 1);

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame(0, $previa->avisoValoresDiferentes['matriculas_valor_antigo']);
        $this->assertSame(1, $previa->avisoValoresDiferentes['matriculas_valor_novo']);
        $this->assertNull($previa->avisoValoresDiferentes['turma_exemplo']);
        $this->assertFalse($previa->exigeConfirmacaoValoresDiferentes());
    }

    public function test_ano_lectivo_encerrado_bloqueia_a_alteracao_de_preco_em_qualquer_modo(): void
    {
        [$plano] = $this->estados();
        $plano->anoLectivo->forceFill(['estado' => EstadoAnoLectivo::ENCERRADO])->saveQuietly();
        $antes = $this->fotografia();

        foreach ([self::APLICAR, self::MANTER] as $modo) {
            foreach (['2027-02', '2026-09'] as $mes) {
                $this->assertRecusa('plano', 'ano lectivo encerrado', fn () => $this->previa($plano, $mes, 3_000_000, $modo));
            }
        }

        $this->assertSame($antes, $this->fotografia());
    }

    /** @return int número de consultas SQL feitas pela pré-visualização */
    private function consultasDaPrevia(PlanoPropina $plano, string $mes, ModoAlteracaoPreco $modo): int
    {
        $plano = $plano->fresh();
        $pedido = $this->pedidoAlteracao($mes, 3_000_000, $modo);
        $n = 0;
        DB::listen(function (QueryExecuted $q) use (&$n) {
            $n++;
        });
        app(AlteracaoPrecoPlanoService::class)->preVisualizar($plano, $pedido);

        return $n;
    }

    public function test_aviso_de_manter_tem_numero_de_consultas_constante_com_mais_turmas(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 1);
        $umaTurma = $this->consultasDaPrevia($plano, '2027-02', self::MANTER);

        foreach (range(1, 5) as $i) {
            $this->matriculaActiva($this->turma($ano, $this->nivel("N-X{$i}")));
        }

        $this->assertSame($umaTurma, $this->consultasDaPrevia($plano, '2027-02', self::MANTER), 'Mais turmas não podem trazer mais consultas (N+1).');
    }

    public function test_a_pre_visualizacao_nao_executa_nenhum_insert_update_ou_delete(): void
    {
        [$plano] = $this->estados();
        $escritas = [];
        DB::listen(function (QueryExecuted $q) use (&$escritas) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql) === 1) {
                $escritas[] = $q->sql;
            }
        });

        foreach ([[self::APLICAR, '2027-02'], [self::MANTER, '2027-02'], [self::APLICAR, '2026-09'], [self::MANTER, '2026-09']] as [$modo, $mes]) {
            $this->previa($plano, $mes, 3_000_000, $modo);
        }

        $this->assertSame([], $escritas);
    }

    /** @return array<string, array{0: callable(PlanoPropina, \Illuminate\Support\Collection): void}> */
    public static function camposDaImpressao(): array
    {
        return [
            'data de início do ano lectivo' => [fn (PlanoPropina $p, $c) => $p->anoLectivo->data_inicio = $p->anoLectivo->data_inicio->addDay()],
            'estado do ano lectivo' => [fn (PlanoPropina $p, $c) => $p->anoLectivo->estado = EstadoAnoLectivo::ENCERRADO],
            'nome do plano' => [fn (PlanoPropina $p, $c) => $p->nome = 'Outro nome'],
            'descrição do plano' => [fn (PlanoPropina $p, $c) => $p->descricao = 'Outra descrição'],
            'mes_fim do plano' => [fn (PlanoPropina $p, $c) => $p->mes_fim = 5],
            'estado do plano' => [fn (PlanoPropina $p, $c) => $p->estado = Estado::INATIVO->value],
            'um alvo do plano' => [fn (PlanoPropina $p, $c) => $p->alvos->first()->curso_id = 999],
            'ordem de uma candidata' => [fn (PlanoPropina $p, $c) => $c->first()->ordem = 99],
        ];
    }

    #[DataProvider('camposDaImpressao')]
    public function test_a_impressao_digital_falha_se_um_campo_sair_dela(callable $mutar): void
    {
        ['ano' => $ano, 'turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $plano = $this->plano($ano, 'Com alvo', ['descricao' => 'Descrição'], [['nivel' => $turma->nivelAcademico]]);
        $this->propina($matricula, $plano, 6);
        $servico = app(AlteracaoPrecoPlanoService::class);
        $pedido = $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR);
        $plano = $plano->fresh(['alvos', 'anoLectivo']);
        $candidatas = Propina::query()->where('plano_propina_id', $plano->id)->get();
        $base = $servico->impressaoDigital($plano, $pedido, $candidatas);
        $this->assertSame($base, $servico->impressaoDigital($plano, $pedido, $candidatas));

        $mutar($plano, $candidatas);

        $this->assertNotSame($base, $servico->impressaoDigital($plano, $pedido, $candidatas));
    }
}
