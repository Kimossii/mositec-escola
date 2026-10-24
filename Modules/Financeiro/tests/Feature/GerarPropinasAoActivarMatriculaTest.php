<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use RuntimeException;
use Tests\TestCase;

/**
 * Q7 + §8.2/3: ao activar uma matrícula, as propinas geram-se DEPOIS do commit, só com «gerar automaticamente»
 * ; uma falha nunca impede nem desfaz a activação e o comando diário recupera.
 * D10: a geração automática nunca recria um período cancelado.
 */
class GerarPropinasAoActivarMatriculaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ligar(): void
    {
        RegraCobranca::doTenant()->update(['gerar_automaticamente' => true]);
    }

    private function pendente(): Matricula
    {
        ['turma' => $turma] = $this->cenarioPropinas();

        return $this->matriculaActiva($turma, '2026-09-01', EstadoMatriculaEnum::PENDENTE);
    }

    private function activar(Matricula $matricula): Matricula
    {
        return app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
    }

    public function test_activar_gera_as_propinas_depois_do_commit_com_origem_matricula(): void
    {
        $this->ligar();
        $matricula = $this->pendente();
        $base = DB::transactionLevel();
        $nivelAoGravar = null;
        $estadoGravado = null;
        Propina::creating(function (Propina $propina) use (&$nivelAoGravar, &$estadoGravado) {
            $nivelAoGravar ??= DB::transactionLevel();
            $estadoGravado ??= Matricula::query()->find($propina->matricula_id)?->estado;
        });

        $this->activar($matricula);

        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());
        $this->assertTrue(Propina::query()->where('matricula_id', $matricula->id)->get()->every(fn (Propina $p) => $p->origem_geracao === OrigemGeracaoPropina::MATRICULA));
        // Só a transacção da própria geração (por matrícula): a da mudança de estado já foi confirmada.
        $this->assertSame($base + 1, $nivelAoGravar);
        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $estadoGravado);
        $this->assertPropinasCoerentes();
    }

    public function test_sem_gerar_automaticamente_nao_gera(): void
    {
        $matricula = $this->pendente();

        $this->activar($matricula);

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_so_reage_a_activacao(): void
    {
        $this->ligar();
        ['matricula' => $activa] = $this->cenarioPropinas();

        app(AlterarEstadoMatriculaAction::class)->executar($activa, EstadoMatriculaEnum::TRANSFERIDA);

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_a_matricula_criada_ja_activa_tambem_gera(): void
    {
        // O despacho na criação está provado em MatriculaEstadoAlteradoTest; aqui, a reacção ao evento com estado anterior nulo.
        $this->ligar();
        ['matricula' => $matricula] = $this->cenarioPropinas();

        Event::dispatch(new MatriculaEstadoAlterado($matricula->id, null, EstadoMatriculaEnum::ACTIVA->value, '2026-09-01', null));

        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());
    }

    public function test_uma_falha_na_geracao_nunca_impede_activar_e_o_comando_recupera(): void
    {
        Log::spy();
        $this->ligar();
        $matricula = $this->pendente();
        $falha = Mockery::mock(GerarPropinasService::class);
        $falha->shouldReceive('gerar')->andThrow(new RuntimeException('Falha simulada da geração.'));
        $this->app->instance(GerarPropinasService::class, $falha);

        $activada = $this->activar($matricula);

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $activada->estado);
        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->refresh()->estado);
        $this->assertSame(0, Propina::query()->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensagem, array $contexto = []) => $mensagem === 'financeiro.gerar-propinas.activacao-falhou'
                && ($contexto['matricula_id'] ?? null) === $matricula->id
                && ($contexto['mensagem'] ?? null) === 'Falha simulada da geração.')
            ->once();

        $this->app->forgetInstance(GerarPropinasService::class);
        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());

        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());
    }

    public function test_a_geracao_automatica_nao_recria_um_periodo_cancelado_mas_a_manual_sim(): void
    {
        $this->ligar();
        ['ano' => $ano, 'turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $outubro = Propina::query()->where('matricula_id', $matricula->id)->where('periodo_inicio', '2026-10-01')->firstOrFail();
        app(CancelarPropinaAction::class)->executar($outubro, new CancelarPropinaDTO('Isenção de Outubro'), null);

        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->assertSame(9, Propina::query()->activas()->where('matricula_id', $matricula->id)->count(), 'O comando não desfaz o cancelamento.');

        $previa = app(GerarPropinasService::class)->preVisualizar(PedidoGeracaoPropinasDTO::automatica($ano->id))->toArray()['matriculas'][0];
        $this->assertContains('Out/2026', array_column($previa['ignoradas'], 'periodo'));

        $manual = app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $ano->id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: $turma->id,
        ));
        $this->assertSame(['Out/2026'], array_column($manual->toArray()['matriculas'][0]['criadas'], 'periodo'));
        $this->assertSame(
            [EstadoCobranca::CANCELADA, EstadoCobranca::EM_ABERTO],
            Propina::query()->where('matricula_id', $matricula->id)->where('periodo_inicio', '2026-10-01')->orderBy('id')->get()->pluck('estado')->all(),
        );
    }

    public function test_a_pre_visualizacao_manual_assinala_um_periodo_cancelado_que_volta_a_gerar_o2(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $outubro = $this->propina($matricula, $plano, 2);
        app(CancelarPropinaAction::class)->executar($outubro, new CancelarPropinaDTO('Isenção de Outubro'), null);

        $previa = app(GerarPropinasService::class)->preVisualizar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $ano->id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: $turma->id,
        ))->toArray()['matriculas'][0];
        $avisos = array_column($previa['criadas'], 'regenera_cancelada', 'periodo');

        // 10:00 UTC = 11:00 em Luanda: cancelada a 15/09/2026 no dia da escola.
        $this->assertSame('Este período tinha sido cancelado em 15/09/2026 («Isenção de Outubro»): confirmar volta a cobrá-lo.', $avisos['Out/2026']);
        $this->assertNull($avisos['Set/2026']);
        $this->assertNull($avisos['Nov/2026']);
    }

    public function test_se_a_transaccao_da_activacao_reverte_nao_gera_nada(): void
    {
        $this->ligar();
        $matricula = $this->pendente();

        try {
            DB::transaction(function () use ($matricula) {
                $this->activar($matricula);

                throw new RuntimeException('Reverter a activação.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(EstadoMatriculaEnum::PENDENTE, $matricula->refresh()->estado);
        $this->assertSame(0, Propina::query()->count());
    }

    public function test_a_geracao_automatica_com_origem_matricula_nao_recria_o_periodo_cancelado_d10(): void
    {
        $this->ligar();
        ['ano' => $ano, 'turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $outubro = Propina::query()->where('matricula_id', $matricula->id)->where('periodo_inicio', '2026-10-01')->firstOrFail();
        app(CancelarPropinaAction::class)->executar($outubro, new CancelarPropinaDTO('Isenção de Outubro'), null);

        $resultado = app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $ano->id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MATRICULA,
            alvo_id: $turma->id,
        ));

        $this->assertSame([], $resultado->toArray()['matriculas'][0]['criadas']);
        $this->assertSame(1, Propina::query()->where('matricula_id', $matricula->id)->where('periodo_inicio', '2026-10-01')->count());
    }
}
