<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\ReconciliarCancelamentosDePropinas;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use RuntimeException;
use Tests\TestCase;

/**
 * D12 (Q5: "reconciliação diária como rede de segurança em qualquer caso"; trancamento D14): se o cancelamento por
 * estado da matrícula não aconteceu (listener perdido, dados antigos, estado mudado fora da Action), o comando
 * diário cancela as propinas futuras canceláveis das matrículas terminadas. Idempotente.
 */
class ReconciliarCancelamentosDePropinasTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

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

    private function correr(): PendingCommand
    {
        return $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo]);
    }

    /** Matrícula com as 10 propinas mensais, depois terminada "à margem" (sem a Action: o listener nunca correu). */
    private function terminadaSemCancelamento(Turma $turma, PlanoPropina $plano, EstadoMatriculaEnum $estado, string $dataFim): Matricula
    {
        $matricula = $this->matriculaActiva($turma);

        foreach (range(1, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        $matricula->forceFill(['estado' => $estado, 'data_fim' => $dataFim])->save();

        return $matricula;
    }

    /** @return list<int> */
    private function ordensCanceladas(Matricula $matricula): array
    {
        return Propina::query()->where('matricula_id', $matricula->id)->where('estado', EstadoCobranca::CANCELADA->value)
            ->orderBy('ordem')->pluck('ordem')->map(fn ($ordem) => (int) $ordem)->all();
    }

    public function test_o_comando_corrige_um_cancelamento_que_ficou_por_fazer_e_e_idempotente(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $matricula = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');

        // gerar_automaticamente está desligado (omissão): a reconciliação corre na mesma.
        $this->correr()->expectsOutputToContain('reconciliação: 8 propina(s) cancelada(s)')->assertSuccessful();

        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
        $novembro = Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 3)->firstOrFail();
        $this->assertSame('Matrícula cancelada em 16/10/2026 (estado da matrícula, reconciliação diária).', $novembro->motivo_cancelamento);
        $this->assertNull($novembro->cancelado_por);

        $this->correr()->assertSuccessful();
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
        $this->assertPropinasCoerentes();
    }

    public function test_corre_mesmo_com_a_geracao_automatica_desligada(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $matricula = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::TRANSFERIDA, '2027-01-31');
        RegraCobranca::doTenant()->update(['gerar_automaticamente' => false]);

        $this->correr()->assertSuccessful();

        // Fev/2027 (ordem 6) em diante: começam depois de 31/01/2027.
        $this->assertSame([6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
    }

    public function test_nao_toca_em_matriculas_activas_nem_em_propinas_com_pagamentos(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $activa] = $this->cenarioPropinas();
        $activaPropina = $this->propina($activa, $plano, 10);
        $terminada = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CONCLUIDA, '2027-03-31');
        $abril = Propina::query()->where('matricula_id', $terminada->id)->where('ordem', 8)->firstOrFail();
        $this->comEstado($abril, EstadoCobranca::PARCIALMENTE_PAGA, 100_000);

        $resultado = app(ReconciliarCancelamentosDePropinas::class)->executar();

        $this->assertSame(['matriculas' => 1, 'canceladas' => 2, 'erros' => 0], $resultado);
        $this->assertSame([9, 10], $this->ordensCanceladas($terminada));
        $this->assertSame(EstadoCobranca::EM_ABERTO, $activaPropina->refresh()->estado);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $abril->refresh()->estado);
    }

    public function test_uma_falha_numa_matricula_nao_impede_as_outras_e_o_tenant_fica_falhado(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $primeira = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');
        $segunda = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');
        Propina::updating(function (Propina $propina) use ($primeira) {
            if ($propina->matricula_id === $primeira->id) {
                throw new RuntimeException('Falha simulada na reconciliação.');
            }
        });

        $this->correr()->assertFailed();

        $this->assertSame([], $this->ordensCanceladas($primeira), 'A transacção da primeira reverteu por inteiro.');
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($segunda));
    }

    public function test_uma_terminal_sem_data_fim_e_ignorada(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $matricula = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');
        $matricula->forceFill(['data_fim' => null])->save();

        $this->assertSame(['matriculas' => 0, 'canceladas' => 0, 'erros' => 0], app(ReconciliarCancelamentosDePropinas::class)->executar());
        $this->assertSame([], $this->ordensCanceladas($matricula));
    }

    public function test_matriculas_conta_so_as_que_tiveram_propinas_canceladas(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $feita = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');
        $so_historico = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');
        Propina::query()->where('matricula_id', $so_historico->id)->where('ordem', '>', 2)
            ->get()->each(fn (Propina $propina) => $this->comEstado($propina, EstadoCobranca::PARCIALMENTE_PAGA, 100_000));

        $resultado = app(ReconciliarCancelamentosDePropinas::class)->executar();

        $this->assertSame(['matriculas' => 1, 'canceladas' => 8, 'erros' => 0], $resultado);
        $this->assertSame([], $this->ordensCanceladas($so_historico));
        $this->assertNotSame([], $this->ordensCanceladas($feita));
    }

    public function test_com_tenant_nao_toca_noutro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $minha = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');
        $dela = $this->noTenant($outro, function () {
            ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();

            return $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16')->id;
        });

        $this->correr()->assertSuccessful();

        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($minha));
        $this->assertSame(0, $this->noTenant($outro, fn () => Propina::query()->where('matricula_id', $dela)->where('estado', EstadoCobranca::CANCELADA->value)->count()));
    }
}
