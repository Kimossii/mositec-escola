<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Modules\Financeiro\Actions\AtualizarConfiguracaoMonetariaAction;
use Modules\Financeiro\Contracts\FonteDePagamentoDePropina;
use Modules\Financeiro\DTO\ConfiguracaoMonetariaDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\ContribuicaoDePagamento;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\FontesDePagamentoDePropina;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\PropinasReferenciam;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Usuario\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class PropinaRobustezTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function umaPropina(int $ordem = 1): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano, $ordem);
    }

    // 1) Estados terminais

    #[DataProvider('terminais')]
    public function test_um_estado_terminal_nunca_volta_atras(EstadoCobranca $terminal): void
    {
        $propina = $this->comEstado($this->umaPropina(), $terminal);

        foreach ([EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA, EstadoCobranca::PAGA, EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA] as $destino) {
            if ($destino === $terminal) {
                continue;
            }

            try {
                $propina->forceFill(['estado' => $destino])->save();
                $this->fail("{$terminal->label()} → {$destino->label()} devia ser recusado.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('terminal', $e->getMessage());
            }

            $propina->refresh();
            $this->assertSame($terminal, $propina->estado);
        }
    }

    public static function terminais(): array
    {
        return ['cancelada' => [EstadoCobranca::CANCELADA], 'anulada' => [EstadoCobranca::ANULADA]];
    }

    public function test_transicoes_de_estados_nao_terminais_continuam_permitidas(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        $this->comEstado($propina, EstadoCobranca::PARCIALMENTE_PAGA);
        $this->comEstado($propina, EstadoCobranca::PAGA);
        $this->comEstado($propina, EstadoCobranca::EM_ABERTO);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->refresh()->estado);

        $this->comEstado($propina, EstadoCobranca::CANCELADA); // acção manual a partir de Em Aberto
        $this->assertSame(EstadoCobranca::CANCELADA, $propina->refresh()->estado);

        $outra = $this->propina($matricula, $plano, 2);
        $this->comEstado($outra, EstadoCobranca::ANULADA);
        $this->assertSame(EstadoCobranca::ANULADA, $outra->refresh()->estado);
    }

    public function test_parcial_nao_vai_directamente_para_cancelada(): void
    {
        $propina = $this->comEstado($this->umaPropina(), EstadoCobranca::PARCIALMENTE_PAGA);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Transição inválida');

        $propina->forceFill(['estado' => EstadoCobranca::CANCELADA])->save();
    }

    public function test_valor_plano_e_ordem_continuam_mutaveis_numa_propina_terminal(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $outroPlano = $this->plano($ano, 'Outro Plano');
        $propina = $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::CANCELADA);

        $propina->forceFill([
            'valor' => Dinheiro::deUnidadesMenores(3_000_000),
            'plano_propina_id' => $outroPlano->id,
            'ordem' => 7,
        ])->save();

        $propina->refresh();
        $this->assertSame(3_000_000, $propina->valor->unidadesMenores());
        $this->assertSame($outroPlano->id, $propina->plano_propina_id);
        $this->assertSame(7, $propina->ordem);
        $this->assertSame(EstadoCobranca::CANCELADA, $propina->estado);
    }

    public function test_o_recalculo_nao_toca_numa_propina_terminal(): void
    {
        $this->fontePagamentoFalsa();
        $propina = $this->comEstado($this->umaPropina(), EstadoCobranca::ANULADA);

        DB::transaction(fn () => app(RecalcularPropina::class)->executar($propina));

        $this->assertSame(EstadoCobranca::ANULADA, $propina->refresh()->estado);
    }

    // 2) Fontes de pagamento e executarVarias

    public function test_executar_varias_recalcula_todas_por_id_crescente(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $fonte = $this->fontePagamentoFalsa();
        $a = $this->propina($matricula, $plano, 1);
        $b = $this->propina($matricula, $plano, 2);
        $fonte->definir($a, [[2_500_000, '2026-09-05']]);
        $fonte->definir($b, [[1_000_000, '2026-10-05']]);

        $resultado = DB::transaction(fn () => app(RecalcularPropina::class)->executarVarias([$b->id, $a->id, $b->id]));

        $this->assertSame([$a->id, $b->id], array_map(fn (Propina $p) => $p->id, $resultado));
        $this->assertSame(EstadoCobranca::PAGA, $a->refresh()->estado);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $b->refresh()->estado);
        $this->assertPropinasCoerentes();
    }

    public function test_executar_varias_exige_transaccao_e_propinas_existentes(): void
    {
        $propina = $this->umaPropina();

        try {
            DB::transaction(fn () => app(RecalcularPropina::class)->executarVarias([$propina->id, 999_999]));
            $this->fail('Devia recusar ids inexistentes.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('não existem no tenant corrente', $e->getMessage());
        }
    }

    public function test_executar_varias_nao_deixa_ler_propinas_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idAlheio = $this->noTenant($outro, fn () => $this->umaPropina()->id);

        $this->expectException(LogicException::class);

        DB::transaction(fn () => app(RecalcularPropina::class)->executarVarias([$idAlheio]));
    }

    public function test_um_servico_etiquetado_sem_a_interface_e_recusado(): void
    {
        $this->app->instance('fonte.errada', new stdClass());
        $this->app->tag(['fonte.errada'], FontesDePagamentoDePropina::ETIQUETA);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tem de implementar');

        app(FontesDePagamentoDePropina::class)->contribuicoes($this->umaPropina());
    }

    public function test_uma_fonte_que_devolve_algo_que_nao_e_contribuicao_e_recusada(): void
    {
        $this->app->instance('fonte.lixo', new class implements FonteDePagamentoDePropina {
            public function contribuicoes(Propina $propina): array
            {
                return [['valor' => 100]];
            }
        });
        $this->app->tag(['fonte.lixo'], FontesDePagamentoDePropina::ETIQUETA);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('em vez de');

        app(FontesDePagamentoDePropina::class)->contribuicoes($this->umaPropina());
    }

    public function test_contribuicao_com_data_futura_e_recusada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('não pode estar no futuro');

        new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(100), CarbonImmutable::now()->addDay());
    }

    public function test_contribuicao_de_hoje_e_aceite(): void
    {
        $contribuicao = new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(100), CarbonImmutable::now());

        $this->assertSame(100, $contribuicao->valor->unidadesMenores());
    }

    public function test_empate_na_mesma_data_entre_duas_fontes_liquida_nesse_dia(): void
    {
        $pagamentos = $this->fontePagamentoFalsa();
        $creditos = $this->fontePagamentoFalsa('fonte.credito.falsa');
        $propina = $this->umaPropina();
        $pagamentos->definir($propina, [[1_000_000, '2026-09-05'], [500_000, '2026-09-20']]);
        $creditos->definir($propina, [[1_000_000, '2026-09-20']]);

        $recalculada = DB::transaction(fn () => app(RecalcularPropina::class)->executar($propina));

        $this->assertSame(EstadoCobranca::PAGA, $recalculada->estado);
        $this->assertSame('2026-09-20', $recalculada->capital_liquidado_em->toDateString());
    }

    // 3) Autoria sem utilizador autenticado

    public function test_sem_utilizador_autenticado_preserva_o_ultimo_editor(): void
    {
        $propina = $this->umaPropina();
        $editor = User::create(['name' => 'Editor', 'email' => 'editor-fin@example.com', 'password' => Hash::make('segredo123')])->id;
        $propina->forceFill(['editado_por' => $editor])->saveQuietly();

        $this->assertNull(auth()->id());
        $propina->refresh()->forceFill(['valor' => Dinheiro::deUnidadesMenores(3_000_000)])->save();

        $this->assertSame($editor, $propina->refresh()->editado_por);
    }

    // 4) Paridade SQL x PHP: casos extra

    private function assertParidade(CarbonImmutable $hoje, string $rotulo): void
    {
        $porEstado = Propina::query()->orderBy('id')->get()->groupBy(fn (Propina $p) => $p->estadoResolvido($hoje)->value);

        foreach (EstadoCobranca::cases() as $estado) {
            $sql = Propina::query()->comEstadoResolvido($estado, $hoje)->orderBy('id')->pluck('id')->all();
            $this->assertSame(($porEstado[$estado->value] ?? collect())->pluck('id')->all(), $sql, "{$estado->label()} ({$rotulo})");
        }
    }

    public function test_paridade_com_tolerancia_zero(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $zero = $this->propina($matricula, $plano, 2, ['dias_tolerancia' => 0, 'data_vencimento' => '2026-10-10']);

        $this->assertSame('2026-10-10', $zero->refresh()->data_limite->toDateString());
        $this->assertParidade(CarbonImmutable::parse('2026-10-10'), 'no vencimento');
        $this->assertParidade(CarbonImmutable::parse('2026-10-11'), 'um dia depois');
        $this->assertSame(EstadoCobranca::EM_ABERTO, $zero->estadoResolvido(CarbonImmutable::parse('2026-10-10')));
        $this->assertSame(EstadoCobranca::EM_ATRASO, $zero->estadoResolvido(CarbonImmutable::parse('2026-10-11')));
    }

    public function test_paridade_compara_o_dia_civil_com_hora_e_fuso(): void
    {
        $this->cenarioDeEstados();

        foreach ([
            CarbonImmutable::parse('2026-10-15 23:59:59', 'Pacific/Auckland'),
            CarbonImmutable::parse('2026-10-15 23:59:59', 'America/Los_Angeles'),
            CarbonImmutable::parse('2026-10-16 00:00:01', 'America/Los_Angeles'),
            CarbonImmutable::parse('2026-08-31 23:30:00', 'Asia/Tokyo'),
            CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'),
        ] as $hoje) {
            $this->assertParidade($hoje, $hoje->toIso8601String());
        }

        $p = Propina::query()->orderBy('id')->first();
        $this->assertSame(EstadoCobranca::EM_ABERTO, $p->estadoResolvido(CarbonImmutable::parse('2026-09-15 23:59:59', 'Pacific/Auckland')));
        $this->assertSame(EstadoCobranca::EM_ATRASO, $p->estadoResolvido(CarbonImmutable::parse('2026-09-16 00:00:01', 'America/Los_Angeles')));
    }

    public function test_paga_continua_paga_quando_capital_liquidado_em_muda(): void
    {
        $paga = $this->comEstado($this->umaPropina(), EstadoCobranca::PAGA);

        foreach (['2026-09-01', '2026-12-31', '2027-06-30'] as $dia) {
            $paga->forceFill(['capital_liquidado_em' => $dia])->save();

            $this->assertSame(EstadoCobranca::PAGA, $paga->refresh()->estadoResolvido(CarbonImmutable::parse('2027-07-01')));
            $this->assertParidade(CarbonImmutable::parse('2027-07-01'), "liquidada em {$dia}");
            $this->assertParidade(CarbonImmutable::parse('2026-09-16'), "liquidada em {$dia}, em atraso para as outras");
        }
    }

    // 5) Bloqueio da moeda

    private function semPrecosDeConfiguracao(): void
    {
        // Isola o bloqueio das propinas: sem fontes de preços (o plano de teste também bloquearia a moeda).
        $this->app->instance(FontesDePrecos::class, new FontesDePrecos(new Container()));
    }

    public function test_a_moeda_nao_muda_enquanto_existirem_propinas(): void
    {
        $this->umaPropina();
        $this->semPrecosDeConfiguracao();

        try {
            app(AtualizarConfiguracaoMonetariaAction::class)->executar(new ConfiguracaoMonetariaDTO('USD', false));
            $this->fail('A mudança de moeda devia ser recusada.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('moeda', $e->errors());
        }

        $this->assertSame('AOA', ConfiguracaoMonetaria::doTenant()->moeda);
    }

    public function test_sem_propinas_e_sem_precos_a_moeda_muda(): void
    {
        $this->semPrecosDeConfiguracao();

        app(AtualizarConfiguracaoMonetariaAction::class)->executar(new ConfiguracaoMonetariaDTO('USD', false));

        $this->assertSame('USD', ConfiguracaoMonetaria::doTenant()->moeda);
    }

    public function test_gravar_a_mesma_moeda_continua_a_funcionar_com_propinas(): void
    {
        $this->umaPropina();
        $this->semPrecosDeConfiguracao();

        $configuracao = app(AtualizarConfiguracaoMonetariaAction::class)->executar(new ConfiguracaoMonetariaDTO('AOA', true));

        $this->assertSame('AOA', $configuracao->moeda);
        $this->assertTrue((bool) $configuracao->cambio_manual);
    }

    public function test_o_ramo_do_plano_nao_ve_propinas_de_outro_tenant(): void
    {
        ['plano' => $plano] = $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->umaPropina());

        $this->assertFalse(app(PropinasReferenciam::class)->existeReferenciaA($plano));
    }
}
