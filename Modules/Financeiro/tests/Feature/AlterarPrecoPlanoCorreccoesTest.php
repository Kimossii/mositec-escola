<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PDOException;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Correcções à revisão do Grupo C: reassociação sem ajuste (D6), motivo 5–500 (D1), só a unicidade do nome vira
 * «dados mudaram» e 2.º clique também in loco.
 */
class AlterarPrecoPlanoCorreccoesTest extends TestCase
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
        Propina::flushEventListeners();
        PlanoPropina::flushEventListeners();

        parent::tearDown();
    }

    /** @return array{0: PlanoPropina, 1: array<string, Propina>} */
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

    private function consulta(string $mensagem): QueryException
    {
        $pdo = new PDOException($mensagem);
        $pdo->errorInfo = ['23000', 19, $mensagem];
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '23000');

        return new QueryException('sqlite', 'update x set y = ?', [1], $pdo);
    }

    // ---- (a) reassociar sem ajuste -------------------------------------------------------------------------------

    public function test_aplicar_com_divisao_reassocia_as_em_aberto_e_parciais_que_ja_tem_o_valor_sem_criar_ajuste(): void
    {
        [$plano, $e] = $this->estados();

        // 25.000 -> 30.000 in loco (MANTER): o plano passa a 30.000, as propinas ficam a 25.000.
        $this->alterarPreco($plano, '2026-09', 3_000_000, self::MANTER);
        $this->assertSame(0, PropinaAjuste::query()->count());

        // De volta a 25.000 com divisão em Fev/2027: as propinas desde Fev já têm este valor.
        $previa = $this->previa($plano, '2027-02', 2_500_000, self::APLICAR);
        $this->assertSame(0, $previa->totais['afectadas']);
        $this->assertSame(4, $previa->totais['reassociadas_sem_ajuste']);
        $this->assertSame(
            [$e['fev_aberta']->id, $e['mar_parcial']->id, $e['mai_aberta']->id, $e['jun_aberta']->id],
            array_column($previa->reassociadasSemAjuste, 'propina_id'),
        );
        $this->assertSame([1, 2, 4, 5], array_column($previa->reassociadasSemAjuste, 'ordem_depois'));
        $this->assertSame($previa->reassociadasSemAjuste, $previa->toArray()['reassociadas_sem_ajuste']);

        $alteracao = $this->alterarPreco($plano, '2027-02', 2_500_000, self::APLICAR);
        $novo = $alteracao->planoNovo;

        foreach (['fev_aberta' => 1, 'mar_parcial' => 2, 'mai_aberta' => 4, 'jun_aberta' => 5] as $chave => $ordem) {
            $p = $e[$chave]->fresh();
            $this->assertSame([$novo->id, $ordem, 2_500_000], [$p->plano_propina_id, $p->ordem, $p->valor->unidadesMenores()], $chave);
        }

        $this->assertSame(0, PropinaAjuste::query()->count(), 'Sem mudança de valor não há ajuste.');
        $this->assertSame(0, $alteracao->total_propinas_afectadas);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $e['mar_parcial']->fresh()->estado);
        $this->assertSame(2_000_000, $e['mar_parcial']->fresh()->valor_pago->unidadesMenores());
        $this->assertSame($plano->id, $e['abr_paga']->fresh()->plano_propina_id, 'Paga fica no plano antigo.');
        $this->assertSame($plano->id, $e['set_aberta']->fresh()->plano_propina_id);
        $this->assertPropinasCoerentes();
        $this->assertSemBuracoNemDuplicacao();
    }

    public function test_um_2_clique_com_reassociacoes_sem_ajuste_e_recusado_e_nada_muda(): void
    {
        [$plano] = $this->estados();
        $this->alterarPreco($plano, '2026-09', 3_000_000, self::MANTER);
        $previa = $this->previa($plano, '2027-02', 2_500_000, self::APLICAR);
        $pedido = $this->pedidoAlteracao('2027-02', 2_500_000, self::APLICAR, 'Motivo válido', $previa->impressaoDigital, true);

        app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido);
        $depois = $this->fotografia();

        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido));
        $this->assertSame($depois, $this->fotografia());
    }

    public function test_a_impressao_digital_muda_se_a_lista_de_reassociacoes_mudar(): void
    {
        [$plano] = $this->estados();
        $servico = app(AlteracaoPrecoPlanoService::class);
        $dto = $this->pedidoAlteracao('2027-02', 2_500_000, self::APLICAR);
        $plano = $plano->fresh();

        $vazia = $servico->impressaoDigital($plano, $dto, new EloquentCollection(), []);
        $com12 = $servico->impressaoDigital($plano, $dto, new EloquentCollection(), [1, 2]);
        $com13 = $servico->impressaoDigital($plano, $dto, new EloquentCollection(), [1, 3]);

        $this->assertSame($com12, $servico->impressaoDigital($plano, $dto, new EloquentCollection(), [1, 2]));
        $this->assertCount(3, array_unique([$vazia, $com12, $com13]));
    }

    public function test_mensagem_final_com_so_reassociacoes_diz_quantas_mudaram_de_plano(): void
    {
        [$plano] = $this->estados();
        $this->alterarPreco($plano, '2026-09', 3_000_000, self::MANTER);
        $impressao = $this->previa($plano, '2027-02', 2_500_000, self::APLICAR)->impressaoDigital;

        $this->from('/origem')->post(route('financeiro.configuracao.planos-propina.alterar-preco', $plano->id), [
            'valor' => '25000', 'mes_efeito' => '2027-02', 'modo' => self::APLICAR->value,
            'motivo' => 'Motivo válido', 'impressao_digital' => $impressao,
        ])->assertSessionHas('success', fn (string $m) => str_contains($m, '0 propina(s) reajustada(s) e 4 mudada(s) de plano.'));
    }

    public function test_manter_com_divisao_nao_reassocia_nada(): void
    {
        [$plano, $e] = $this->estados();
        $this->alterarPreco($plano, '2026-09', 3_000_000, self::MANTER);
        $previa = $this->previa($plano, '2027-02', 2_500_000, self::MANTER);

        $this->assertSame([], $previa->reassociadasSemAjuste);
    }

    public function test_in_loco_nao_tem_reassociacoes(): void
    {
        [$plano] = $this->estados();
        $this->alterarPreco($plano, '2026-09', 3_000_000, self::MANTER);

        $this->assertSame([], $this->previa($plano, '2026-09', 2_500_000, self::APLICAR)->reassociadasSemAjuste);
    }

    private function assertSemBuracoNemDuplicacao(): void
    {
        $vistas = [];

        // Só as que ainda se cobram (Em Aberto/Parcial): as Pagas ficam, por desenho (D2), no plano onde foram geradas.
        $porCobrar = Propina::query()->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])->with('plano')->get();

        foreach ($porCobrar as $p) {
            $periodo = $p->plano->periodos()[$p->ordem - 1] ?? null;
            $this->assertNotNull($periodo, "propina {$p->id}: o plano {$p->plano->nome} não tem a ordem {$p->ordem}");
            $this->assertSame($p->periodo_inicio->toDateString(), $periodo['inicio'], "propina {$p->id}: o calendário do plano contém o seu período");
            $chave = $p->matricula_id . '|' . $p->periodo_inicio->toDateString();
            $this->assertArrayNotHasKey($chave, $vistas, 'Dupla cobrança do mesmo período.');
            $vistas[$chave] = true;
        }

        $this->assertCount(6, $vistas, 'Nenhum período por cobrar desapareceu (6 Em Aberto/Parciais).');
    }

    // ---- (b) motivo 5–500 ----------------------------------------------------------------------------------------

    public function test_motivo_tem_de_ter_entre_5_e_500_caracteres(): void
    {
        [$plano] = $this->estados();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;
        $acao = app(AlterarPrecoPlanoAction::class);
        $antes = $this->fotografia();

        foreach (['abcd', ' abcd ', str_repeat('é', 501)] as $motivo) {
            $this->assertRecusa('motivo', '5', fn () => $acao->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, $motivo, $impressao)));
        }

        $this->assertSame($antes, $this->fotografia());
        $this->alterarPreco($plano, '2027-02', 3_000_000, self::APLICAR, str_repeat('é', 500));
        $this->assertSame(str_repeat('é', 500), AlteracaoPrecoPlano::query()->firstOrFail()->motivo);
    }

    public function test_motivo_com_5_caracteres_e_aceite(): void
    {
        [$plano] = $this->estados();

        $this->assertSame('abcde', $this->alterarPreco($plano, '2027-02', 3_000_000, self::MANTER, ' abcde ')->motivo);
    }

    // ---- (c) unicidade ------------------------------------------------------------------------------------------

    public function test_unicidade_do_nome_do_plano_novo_vira_dados_mudaram(): void
    {
        [$plano] = $this->estados();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;
        $antes = $this->fotografia();
        $erro = $this->consulta('UNIQUE constraint failed: planos_propina.tenant_id, planos_propina.ano_lectivo_id, planos_propina.nome');
        PlanoPropina::creating(fn () => throw $erro);

        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => app(AlterarPrecoPlanoAction::class)->executar(
            $plano->fresh(),
            $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo válido', $impressao),
        ));
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_outra_violacao_de_unicidade_propaga_como_defeito(): void
    {
        [$plano] = $this->estados();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;
        $antes = $this->fotografia();
        $erro = $this->consulta('UNIQUE constraint failed: propinas.tenant_id, propinas.matricula_id, propinas.plano_propina_id, propinas.ordem');
        Propina::updating(fn (Propina $p) => $p->isDirty('plano_propina_id') ? throw $erro : null);

        try {
            app(AlterarPrecoPlanoAction::class)->executar(
                $plano->fresh(),
                $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo válido', $impressao),
            );
            $this->fail('A violação de propinas_plano_ordem_activo_unique tem de propagar.');
        } catch (QueryException $e) {
            $this->assertSame($erro, $e);
        }

        Propina::flushEventListeners();
        $this->assertSame($antes, $this->fotografia(), 'A transacção reverteu tudo.');
    }

    // ---- (d) 2.º clique in loco ----------------------------------------------------------------------------------

    public function test_segundo_clique_in_loco_aplicar_e_recusado_e_nada_se_duplica(): void
    {
        [$plano] = $this->estados();
        $impressao = $this->previa($plano, '2026-09', 3_000_000, self::APLICAR)->impressaoDigital;
        $pedido = $this->pedidoAlteracao('2026-09', 3_000_000, self::APLICAR, 'Motivo válido', $impressao);

        app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido);
        $depois = $this->fotografia();

        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido));
        $this->assertSame($depois, $this->fotografia());
        $this->assertSame(1, AlteracaoPrecoPlano::query()->count());
    }

    public function test_segundo_clique_in_loco_manter_e_recusado_e_nada_se_duplica(): void
    {
        [$plano] = $this->estados();
        $impressao = $this->previa($plano, '2026-09', 3_000_000, self::MANTER)->impressaoDigital;
        $pedido = $this->pedidoAlteracao('2026-09', 3_000_000, self::MANTER, 'Motivo válido', $impressao, true);

        app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido);
        $depois = $this->fotografia();

        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido));
        $this->assertSame($depois, $this->fotografia());
        $this->assertSame(1, AlteracaoPrecoPlano::query()->count());
    }
}
