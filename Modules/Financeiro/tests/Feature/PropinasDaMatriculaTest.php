<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Actions\AtualizarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

/**
 * Q8 (§8.1): com propinas activas, a matrícula não muda directamente de turma nem de ano lectivo, nem de mês de
 * matrícula (as propinas foram geradas para essa turma/plano/ano e a partir desse mês). O dia, dentro do mesmo
 * mês, continua livre. A mensagem indica o fluxo correcto.
 */
class PropinasDaMatriculaTest extends TestCase
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

    /** @return array{0: Matricula, 1: Turma, 2: PlanoPropina} */
    private function cenario(): array
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return [$matricula, $this->turma($ano, $turma->nivelAcademico), $plano];
    }

    private function actualizar(Matricula $matricula, Turma $turma, string $data): Matricula
    {
        return app(AtualizarMatriculaAction::class)->executar($matricula, new MatriculaDTO(
            turmaId: $turma->id,
            anoLectivoId: $turma->ano_lectivo_id,
            dataMatricula: $data,
            estado: null,
            observacoes: null,
        ));
    }

    public function test_com_propinas_activas_nao_muda_de_turma_e_a_mensagem_indica_a_transferencia(): void
    {
        [$matricula, $outraTurma, $plano] = $this->cenario();
        $this->propina($matricula, $plano, 1);

        try {
            $this->actualizar($matricula, $outraTurma, '2026-09-01');
            $this->fail('Devia bloquear.');
        } catch (ValidationException $e) {
            $mensagem = $e->errors()['turma_id'][0];
            $this->assertStringContainsString('1 propina(s) activa(s)', $mensagem);
            $this->assertStringContainsString('Transferida', $mensagem);
            $this->assertStringContainsString('Financeiro → Propinas', $mensagem);
        }

        $this->assertSame($matricula->turma_id, $matricula->refresh()->turma_id);
    }

    public function test_uma_propina_paga_tambem_conta_como_activa(): void
    {
        [$matricula, $outraTurma, $plano] = $this->cenario();
        $this->comEstado($this->propina($matricula, $plano, 1), EstadoCobranca::PAGA);

        $this->expectException(ValidationException::class);

        $this->actualizar($matricula, $outraTurma, '2026-09-01');
    }

    public function test_com_propinas_activas_nao_muda_o_mes_mas_muda_o_dia(): void
    {
        [$matricula, , $plano] = $this->cenario();
        $this->propina($matricula, $plano, 1);
        $turma = $matricula->turma;

        try {
            $this->actualizar($matricula, $turma, '2026-10-01');
            $this->fail('Mudar o mês devia ser bloqueado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('mesmo mês', $e->errors()['data_matricula'][0]);
        }

        $this->assertSame('2026-09-15', $this->actualizar($matricula, $turma, '2026-09-15')->data_matricula->toDateString());
    }

    public function test_sem_propinas_ou_so_com_canceladas_muda_livremente(): void
    {
        [$matricula, $outraTurma, $plano] = $this->cenario();
        $turmaOriginal = $matricula->turma;

        // Sem propinas: muda de turma e de mês.
        $this->assertSame($outraTurma->id, $this->actualizar($matricula, $outraTurma, '2026-10-01')->turma_id);

        // Só com uma propina cancelada: volta à turma original e muda outra vez de mês.
        $this->comEstado($this->propina($matricula->refresh(), $plano, 1), EstadoCobranca::CANCELADA);
        $voltou = $this->actualizar($matricula->refresh(), $turmaOriginal, '2026-10-01');
        $this->assertSame($turmaOriginal->id, $voltou->turma_id);
        $this->assertSame('2026-11-03', $this->actualizar($voltou, $turmaOriginal, '2026-11-03')->data_matricula->toDateString());
    }

    public function test_por_http_o_erro_chega_ao_campo_turma(): void
    {
        [$matricula, $outraTurma, $plano] = $this->cenario();
        $this->propina($matricula, $plano, 1);
        $this->actingAs($this->adminEscola());

        $this->from('/matriculas')
            ->put(route('matriculas.update', [$matricula->aluno_id, $matricula->id]), [
                'turma_id' => $outraTurma->id,
                'ano_lectivo_id' => $outraTurma->ano_lectivo_id,
                'data_matricula' => '2026-09-01',
            ])
            ->assertSessionHasErrors('turma_id');

        $this->assertSame($matricula->turma_id, $matricula->refresh()->turma_id);
    }

    public function test_com_propinas_activas_editar_so_as_observacoes_passa(): void
    {
        [$matricula, , $plano] = $this->cenario();
        $this->propina($matricula, $plano, 1);

        $actualizada = app(AtualizarMatriculaAction::class)->executar($matricula, new MatriculaDTO(
            turmaId: $matricula->turma_id,
            anoLectivoId: $matricula->ano_lectivo_id,
            dataMatricula: $matricula->data_matricula->toDateString(),
            estado: null,
            observacoes: 'Só uma nota.',
        ));

        $this->assertSame('Só uma nota.', $actualizada->observacoes);
    }
}
