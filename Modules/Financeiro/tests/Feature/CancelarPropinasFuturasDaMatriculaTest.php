<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\CancelarPropinasFuturasDaMatricula;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use RuntimeException;
use Tests\TestCase;

/**
 * Spec §9 + Q5/Q6: quando a matrícula passa a Cancelada, Transferida ou Concluída, as propinas com início depois
 * da data de efeito, sem pagamentos, são canceladas na MESMA transacção; dívidas e pagamentos ficam.
 */
class CancelarPropinasFuturasDaMatriculaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Matrícula Activa de 01/09/2026 com as 10 propinas mensais (Set/2026 … Jun/2027), todas Em Aberto.
     *
     * @return array{0: Matricula, 1: array<int, Propina>, 2: PlanoPropina}
     */
    private function matriculaComDezPropinas(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propinas = [];

        foreach (range(1, 10) as $ordem) {
            $propinas[$ordem] = $this->propina($matricula, $plano, $ordem);
        }

        return [$matricula, $propinas, $plano];
    }

    /** @return list<int> ordens das propinas Canceladas da matrícula */
    private function ordensCanceladas(Matricula $matricula): array
    {
        return Propina::query()->where('matricula_id', $matricula->id)->where('estado', EstadoCobranca::CANCELADA->value)
            ->orderBy('ordem')->pluck('ordem')->map(fn ($ordem) => (int) $ordem)->all();
    }

    private function alterar(Matricula $matricula, EstadoMatriculaEnum $estado, ?string $dataFim = null, ?int $utilizadorId = null): Matricula
    {
        return app(AlterarEstadoMatriculaAction::class)->executar($matricula, $estado, $utilizadorId, $dataFim);
    }

    public function test_cancelar_a_matricula_cancela_so_as_futuras_sem_pagamentos(): void
    {
        [$matricula, $propinas] = $this->matriculaComDezPropinas();
        $this->comEstado($propinas[4], EstadoCobranca::PARCIALMENTE_PAGA, 500_000); // Dez: pagamento antecipado
        $this->historicoPagamentoFalso()->marcar($propinas[7]);                     // Mar: já teve pagamentos
        $admin = $this->adminEscola();

        $this->alterar($matricula, EstadoMatriculaEnum::CANCELADA, null, $admin->id);

        // data_fim = hoje (16/10/2026): Set e Out (já começaram) ficam como dívida; Dez e Mar ficam (pagamentos).
        $this->assertSame([3, 5, 6, 8, 9, 10], $this->ordensCanceladas($matricula));
        $novembro = $propinas[3]->refresh();
        $this->assertSame('Matrícula cancelada em 16/10/2026 (estado da matrícula).', $novembro->motivo_cancelamento);
        $this->assertSame($admin->id, (int) $novembro->cancelado_por);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propinas[1]->refresh()->estado);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propinas[2]->refresh()->estado);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $propinas[4]->refresh()->estado);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propinas[7]->refresh()->estado);
        $this->assertPropinasCoerentes();
    }

    public function test_transferida_com_data_de_fim_retroactiva_usa_essa_data(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();

        $this->alterar($matricula, EstadoMatriculaEnum::TRANSFERIDA, '2026-09-20');

        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
        $this->assertSame(
            'Matrícula transferida em 20/09/2026 (estado da matrícula).',
            Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 2)->value('motivo_cancelamento'),
        );
    }

    public function test_concluida_tambem_cancela_as_futuras_q6(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();

        $this->alterar($matricula, EstadoMatriculaEnum::CONCLUIDA, '2027-03-31');

        $this->assertSame([8, 9, 10], $this->ordensCanceladas($matricula));
        $this->assertStringStartsWith('Matrícula concluída em 31/03/2027', Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 8)->value('motivo_cancelamento'));
    }

    public function test_o_periodo_que_comeca_na_data_de_efeito_fica(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();

        // O1: a data de fim de uma Cancelada não pode ser futura; Out/2026 começa a 01/10 e fica (não é "depois").
        $this->alterar($matricula, EstadoMatriculaEnum::CANCELADA, '2026-10-01');

        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
    }

    public function test_nao_toca_nas_propinas_de_outras_matriculas(): void
    {
        [$matricula, , $plano] = $this->matriculaComDezPropinas();
        $outra = $this->matriculaActiva($matricula->turma);
        $daOutra = $this->propina($outra, $plano, 10);

        $this->alterar($matricula, EstadoMatriculaEnum::CANCELADA);

        $this->assertSame(EstadoCobranca::EM_ABERTO, $daOutra->refresh()->estado);
    }

    public function test_uma_falha_no_cancelamento_reverte_a_mudanca_de_estado_q5(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();
        $historicoAntes = MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count();
        Propina::updating(function () {
            throw new RuntimeException('Falha simulada ao cancelar.');
        });

        try {
            $this->alterar($matricula, EstadoMatriculaEnum::CANCELADA);
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException $e) {
            $this->assertSame('Falha simulada ao cancelar.', $e->getMessage());
        }

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->refresh()->estado);
        $this->assertNull($matricula->data_fim);
        $this->assertSame($historicoAntes, MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count());
        $this->assertSame([], $this->ordensCanceladas($matricula));
    }

    public function test_o_servico_e_idempotente_e_ignora_matriculas_nao_terminadas(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();
        $servico = app(CancelarPropinasFuturasDaMatricula::class);

        $this->assertSame([], DB::transaction(fn () => $servico->executar($matricula, '2026-10-16', null)), 'Activa: nada a cancelar.');

        $matricula->forceFill(['estado' => EstadoMatriculaEnum::CANCELADA, 'data_fim' => '2026-10-16'])->save();
        $primeira = DB::transaction(fn () => $servico->executar($matricula->refresh(), '2026-10-16', null));
        $segunda = DB::transaction(fn () => $servico->executar($matricula->refresh(), '2026-10-16', null));

        $this->assertCount(8, $primeira);
        $this->assertSame([], $segunda);
    }

    public function test_estados_que_o_financeiro_nao_conhece_sao_ignorados(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();

        // Ex.: um futuro estado "Trancada" (plano de trancamento): o listener não pode falhar nem cancelar nada.
        DB::transaction(fn () => Event::dispatch(new MatriculaEstadoAlterado($matricula->id, EstadoMatriculaEnum::ACTIVA->value, 99, '2026-10-16', null)));

        $this->assertSame([], $this->ordensCanceladas($matricula));
    }

    public function test_uma_propina_futura_paga_ou_anulada_fica_intacta(): void
    {
        [$matricula, $propinas] = $this->matriculaComDezPropinas();
        $this->comEstado($propinas[5], EstadoCobranca::PAGA);
        $this->comEstado($propinas[6], EstadoCobranca::ANULADA);

        $this->alterar($matricula, EstadoMatriculaEnum::CANCELADA, '2026-10-16');

        $this->assertSame([3, 4, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
        $this->assertSame(EstadoCobranca::PAGA, $propinas[5]->refresh()->estado);
        $this->assertSame(EstadoCobranca::ANULADA, $propinas[6]->refresh()->estado);
    }
}
