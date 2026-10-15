<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class AlterarPrecoPlanoTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const APLICAR = ModoAlteracaoPreco::APLICAR_GERADAS;
    private const MANTER = ModoAlteracaoPreco::MANTER_GERADAS;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        $this->actingAs($this->adminEscola());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * cenarioDeEstados com contribuições coerentes com o valor pago em cache (o reajuste recalcula-as).
     *
     * @return array{0: PlanoPropina, 1: array<string, Propina>}
     */
    private function estados(): array
    {
        $e = $this->cenarioDeEstados();
        $fonte = $this->fontePagamentoFalsa();
        $fonte->definir($e['out_parcial'], [[1_000_000, '2026-10-05']]);
        $fonte->definir($e['mar_parcial'], [[2_000_000, '2026-10-05']]);
        $fonte->definir($e['nov_paga'], [[2_500_000, '2026-10-05']]);
        $fonte->definir($e['abr_paga'], [[2_500_000, '2026-10-05']]);

        return [$e['set_aberta']->plano()->firstOrFail(), $e];
    }

    /** @return array<string, mixed> o que uma propina tem de "intocável" fora do valor/plano/ordem */
    private function snapshot(Propina $propina): array
    {
        $p = $propina->fresh();

        return [$p->periodo_inicio->toDateString(), $p->periodo_fim->toDateString(), $p->data_vencimento->toDateString(), $p->dias_tolerancia,
            $p->data_limite->toDateString(), $p->valor_original->unidadesMenores(), $p->valor_pago->unidadesMenores(), $p->moeda];
    }

    public function test_aplicar_divide_o_plano_reassocia_e_reajusta_so_as_em_aberto_e_parciais_a_partir_do_mes_de_efeito(): void
    {
        [$plano, $e] = $this->estados();
        $antes = array_map(fn (Propina $p) => $this->snapshot($p), $e);

        $alteracao = $this->alterarPreco($plano, '2027-02', 3_000_000, self::APLICAR, 'Actualização anual');

        $plano->refresh();
        $novo = PlanoPropina::query()->where('nome', 'Propina Mensal (desde 02/2027)')->firstOrFail();
        $this->assertSame([9, 1, 2_500_000], [$plano->mes_inicio, $plano->mes_fim, $plano->valor->unidadesMenores()]);
        $this->assertSame([2, 6, 3_000_000, $plano->ano_lectivo_id], [$novo->mes_inicio, $novo->mes_fim, $novo->valor->unidadesMenores(), $novo->ano_lectivo_id]);

        foreach (['fev_aberta' => 1, 'mar_parcial' => 2, 'mai_aberta' => 4, 'jun_aberta' => 5] as $chave => $ordem) {
            $p = $e[$chave]->fresh();
            $this->assertSame([$novo->id, $ordem, 3_000_000], [$p->plano_propina_id, $p->ordem, $p->valor->unidadesMenores()], $chave);
            $this->assertSame($antes[$chave], $this->snapshot($p), "{$chave}: período, vencimento, original e pago intactos");
        }

        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $e['mar_parcial']->fresh()->estado);
        $this->assertSame(1_000_000, $e['mar_parcial']->fresh()->saldo()->unidadesMenores());

        foreach (['set_aberta', 'out_parcial', 'nov_paga', 'dez_cancelada', 'jan_anulada', 'abr_paga'] as $chave) {
            $p = $e[$chave]->fresh();
            $this->assertSame([$plano->id, $e[$chave]->ordem, 2_500_000], [$p->plano_propina_id, $p->ordem, $p->valor->unidadesMenores()], $chave);
            $this->assertSame($e[$chave]->estado, $p->estado, $chave);
        }

        $alteracao->refresh();
        $this->assertSame(ModoAlteracaoPreco::APLICAR_GERADAS, $alteracao->modo);
        $this->assertSame([$plano->id, $novo->id, $plano->ano_lectivo_id], [$alteracao->plano_propina_id, $alteracao->plano_novo_id, $alteracao->ano_lectivo_id]);
        $this->assertSame([2_500_000, 3_000_000, 4], [$alteracao->valor_anterior->unidadesMenores(), $alteracao->valor_novo->unidadesMenores(), $alteracao->total_propinas_afectadas]);
        $this->assertSame('2027-02-01', $alteracao->mes_efeito->toDateString());
        $this->assertSame('Actualização anual', $alteracao->motivo);
        $this->assertSame(auth()->id(), $alteracao->criado_por);
        $this->assertSame(64, strlen($alteracao->impressao_digital));

        $ajusteMarco = PropinaAjuste::query()->where('propina_id', $e['mar_parcial']->id)->firstOrFail();
        $this->assertSame([2_500_000, 3_000_000, 2_000_000], [$ajusteMarco->valor_anterior->unidadesMenores(), $ajusteMarco->valor_novo->unidadesMenores(), $ajusteMarco->valor_pago_no_momento->unidadesMenores()]);
        $this->assertSame([$plano->id, $novo->id, 'Actualização anual', auth()->id()], [$ajusteMarco->plano_anterior_id, $ajusteMarco->plano_novo_id, $ajusteMarco->motivo, $ajusteMarco->criado_por]);
        $this->assertSame(4, PropinaAjuste::query()->count());
        $this->assertPropinasCoerentes();
    }

    public function test_manter_divide_o_plano_sem_tocar_em_nenhuma_propina(): void
    {
        [$plano] = $this->estados();
        $propinasAntes = $this->fotografia()['propinas'];

        $alteracao = $this->alterarPreco($plano, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame($propinasAntes, $this->fotografia()['propinas']);
        $this->assertSame(1, $plano->fresh()->mes_fim);
        $this->assertNotNull($alteracao->plano_novo_id);
        $this->assertSame(0, $alteracao->total_propinas_afectadas);
        $this->assertSame(0, PropinaAjuste::query()->count());
        $this->assertPropinasCoerentes();
    }

    public function test_manter_com_alunos_ao_valor_antigo_exige_confirmar_valores_diferentes(): void
    {
        [$plano] = $this->estados();
        $antes = $this->fotografia();

        $this->assertRecusa('confirmar_valores_diferentes', 'valores diferentes', fn () => $this->alterarPreco($plano, '2027-02', 3_000_000, self::MANTER, confirma: false));
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_in_loco_aplicar_reajusta_no_proprio_plano(): void
    {
        [$plano, $e] = $this->estados();

        $alteracao = $this->alterarPreco($plano, '2026-09', 3_000_000, self::APLICAR);

        $this->assertNull($alteracao->plano_novo_id);
        $this->assertSame(1, PlanoPropina::query()->count());
        $this->assertSame([9, 6, 3_000_000], [$plano->fresh()->mes_inicio, $plano->fresh()->mes_fim, $plano->fresh()->valor->unidadesMenores()]);

        foreach (['set_aberta', 'out_parcial', 'fev_aberta', 'mar_parcial', 'mai_aberta', 'jun_aberta'] as $chave) {
            $p = $e[$chave]->fresh();
            $this->assertSame([$plano->id, $e[$chave]->ordem, 3_000_000], [$p->plano_propina_id, $p->ordem, $p->valor->unidadesMenores()], $chave);
        }

        $this->assertSame(6, $alteracao->total_propinas_afectadas);
        $this->assertNull(PropinaAjuste::query()->firstOrFail()->plano_novo_id);
        $this->assertPropinasCoerentes();
    }

    public function test_in_loco_manter_so_muda_o_valor_do_plano(): void
    {
        [$plano] = $this->estados();
        $propinasAntes = $this->fotografia()['propinas'];

        $this->alterarPreco($plano, '2026-09', 3_000_000, self::MANTER);

        $this->assertSame(3_000_000, $plano->fresh()->valor->unidadesMenores());
        $this->assertSame($propinasAntes, $this->fotografia()['propinas']);
    }

    public function test_descida_abaixo_do_pago_fica_no_pago_passa_a_paga_sem_devolucao_e_fica_registada(): void
    {
        [$plano, $e] = $this->estados();

        $this->alterarPreco($plano, '2027-02', 1_500_000, self::APLICAR);

        $marco = $e['mar_parcial']->fresh();
        $this->assertSame([2_000_000, 2_000_000, EstadoCobranca::PAGA], [$marco->valor->unidadesMenores(), $marco->valor_pago->unidadesMenores(), $marco->estado]);
        $this->assertSame('2026-10-05', $marco->capital_liquidado_em?->toDateString());
        $this->assertSame(1_500_000, $e['fev_aberta']->fresh()->valor->unidadesMenores());
        $ajuste = PropinaAjuste::query()->where('propina_id', $marco->id)->firstOrFail();
        $this->assertSame([2_500_000, 2_000_000, 2_000_000], [$ajuste->valor_anterior->unidadesMenores(), $ajuste->valor_novo->unidadesMenores(), $ajuste->valor_pago_no_momento->unidadesMenores()]);
        $this->assertPropinasCoerentes();
    }

    public function test_pagamento_entre_a_previsualizacao_e_a_confirmacao_recusa_e_nada_muda(): void
    {
        [$plano, $e] = $this->estados();
        $previa = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);
        $this->comEstado($e['fev_aberta'], EstadoCobranca::PARCIALMENTE_PAGA, 100_000);
        $antes = $this->fotografia();

        $this->assertRecusa('impressao_digital', 'Os dados mudaram desde a pré-visualização', fn () => app(AlterarPrecoPlanoAction::class)->executar(
            $plano->fresh(),
            $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', $previa->impressaoDigital),
        ));
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_segundo_clique_com_a_mesma_previsualizacao_e_recusado_e_nada_se_duplica(): void
    {
        [$plano] = $this->estados();
        $previa = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);
        $pedido = $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', $previa->impressaoDigital);

        app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido);
        $depoisDoPrimeiro = $this->fotografia();

        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido));
        $this->assertSame($depoisDoPrimeiro, $this->fotografia());
        $this->assertSame(1, AlteracaoPrecoPlano::query()->count());
        $this->assertSame(2, PlanoPropina::query()->count());
    }

    public function test_motivo_e_impressao_digital_sao_obrigatorios(): void
    {
        [$plano] = $this->estados();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;
        $acao = app(AlterarPrecoPlanoAction::class);

        $this->assertRecusa('motivo', 'motivo', fn () => $acao->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, '   ', $impressao)));
        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => $acao->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', null)));
        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => $acao->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', str_repeat('0', 64))));
        $this->assertSame(0, AlteracaoPrecoPlano::query()->count());
    }

    public function test_plano_inactivo_divide_e_o_novo_fica_inactivo(): void
    {
        [$plano, $e] = $this->estados();
        $plano->update(['estado' => Estado::INATIVO->value]);

        $alteracao = $this->alterarPreco($plano, '2027-02', 3_000_000, self::APLICAR);

        $this->assertSame(Estado::INATIVO->value, $alteracao->planoNovo->estado);
        $this->assertSame($alteracao->plano_novo_id, $e['fev_aberta']->fresh()->plano_propina_id);
    }

    public function test_reverter_e_uma_nova_alteracao_auditada_e_os_ajustes_encadeiam(): void
    {
        [$plano, $e] = $this->estados();
        $primeira = $this->alterarPreco($plano, '2027-02', 3_000_000, self::APLICAR, 'Subida');
        $novo = $primeira->planoNovo;

        // M4: reverter = nova alteração sobre o plano novo, in loco (o seu início é Fev/2027), de volta a 25.000,00.
        $this->alterarPreco($novo, '2027-02', 2_500_000, self::APLICAR, 'Reversão: subida aplicada por engano');

        $this->assertSame(2_500_000, $e['fev_aberta']->fresh()->valor->unidadesMenores());
        $this->assertSame(['Subida', 'Reversão: subida aplicada por engano'], $e['fev_aberta']->fresh()->ajustes->pluck('motivo')->all());
        $this->assertSame(2, AlteracaoPrecoPlano::query()->count());
        $this->assertPropinasCoerentes();
    }

    public function test_ano_encerrado_entre_a_previsualizacao_e_a_confirmacao_recusa_dentro_da_transaccao_e_nada_muda(): void
    {
        [$plano] = $this->estados();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;
        $plano->anoLectivo->forceFill(['estado' => EstadoAnoLectivo::ENCERRADO])->saveQuietly();
        $antes = $this->fotografia();

        foreach ([self::APLICAR, self::MANTER] as $modo) {
            $this->assertRecusa('plano', 'ano lectivo encerrado', fn () => app(AlterarPrecoPlanoAction::class)->executar(
                $plano->fresh(),
                $this->pedidoAlteracao('2027-02', 3_000_000, $modo, 'Motivo', $impressao, true),
            ));
        }

        $this->assertSame($antes, $this->fotografia(), 'Nenhuma escrita num ano encerrado (decisão: bloqueado também na escrita).');
    }
}
