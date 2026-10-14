<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use RuntimeException;
use Tests\TestCase;

/**
 * F3 (D1, D6, D7): a Matrícula emite um facto com primitivos, dentro da sua transacção; uma falha de quem
 * escuta reverte tudo (Q5). Sem dependência do Financeiro: os listeners aqui são de teste.
 */
class MatriculaEstadoAlteradoTest extends TestCase
{
    use RefreshDatabase;

    private function novaMatricula(?EstadoMatriculaEnum $estado = null): Matricula
    {
        $estabelecimento = $this->estabelecimentoDeTeste();
        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => '2026',
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '1C',
            'nome' => 'Nível 1C',
            'ordem' => 1,
            'etapa_ensino' => 1,
        ]);
        $turma = Turma::create([
            'ano_lectivo_id' => $ano->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T' . random_int(1000, 9999),
            'nome' => 'Turma Teste',
            'estado' => 1,
        ]);
        $pessoa = DadosPessoa::create([
            'nome_completo' => 'Aluno Teste',
            'numero_identificacao' => 'BI' . random_int(100000, 999999),
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => '2026-' . random_int(1000, 9999),
        ]);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, null, $nivel->id);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => null,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'PL' . random_int(100000, 999999),
            'nome' => 'Plano Curricular Teste',
        ]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);

        return app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(
            turmaId: $turma->id,
            anoLectivoId: $ano->id,
            dataMatricula: '2026-02-01',
            estado: $estado?->value,
            observacoes: null,
        ));
    }

    public function test_a_criacao_despacha_o_evento_com_estado_anterior_nulo(): void
    {
        Event::fake([MatriculaEstadoAlterado::class]);

        $matricula = $this->novaMatricula(EstadoMatriculaEnum::ACTIVA);

        Event::assertDispatchedTimes(MatriculaEstadoAlterado::class, 1);
        Event::assertDispatched(MatriculaEstadoAlterado::class, fn (MatriculaEstadoAlterado $e) => $e->matriculaId === $matricula->id
            && $e->estadoAnterior === null
            && $e->estadoNovo === EstadoMatriculaEnum::ACTIVA->value
            && $e->dataEfeito === '2026-02-01'
            && $e->utilizadorId === null);
    }

    public function test_cada_mudanca_de_estado_despacha_o_evento_com_a_data_de_efeito(): void
    {
        Event::fake([MatriculaEstadoAlterado::class]);
        $matricula = $this->novaMatricula();
        $hoje = app(RelogioDoTenant::class)->hoje()->toDateString();

        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA, dataFim: '2026-03-15');

        Event::assertDispatchedTimes(MatriculaEstadoAlterado::class, 3);
        Event::assertDispatched(MatriculaEstadoAlterado::class, fn (MatriculaEstadoAlterado $e) => $e->estadoAnterior === EstadoMatriculaEnum::PENDENTE->value
            && $e->estadoNovo === EstadoMatriculaEnum::ACTIVA->value
            && $e->dataEfeito === $hoje);
        Event::assertDispatched(MatriculaEstadoAlterado::class, fn (MatriculaEstadoAlterado $e) => $e->estadoAnterior === EstadoMatriculaEnum::ACTIVA->value
            && $e->estadoNovo === EstadoMatriculaEnum::CANCELADA->value
            && $e->dataEfeito === '2026-03-15');
    }

    public function test_o_evento_e_despachado_dentro_da_transaccao_da_mudanca_de_estado(): void
    {
        $matricula = $this->novaMatricula(EstadoMatriculaEnum::ACTIVA);
        $fora = DB::transactionLevel();
        $dentro = null;
        Event::listen(MatriculaEstadoAlterado::class, function () use (&$dentro) {
            $dentro = DB::transactionLevel();
        });

        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::TRANSFERIDA);

        $this->assertGreaterThan($fora, $dentro);
    }

    public function test_uma_falha_de_quem_escuta_reverte_o_estado_a_data_fim_e_o_historico(): void
    {
        $matricula = $this->novaMatricula(EstadoMatriculaEnum::ACTIVA);
        $historicoAntes = MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count();
        Event::listen(MatriculaEstadoAlterado::class, function () {
            throw new RuntimeException('Falha simulada de um módulo que escuta.');
        });

        try {
            app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA);
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException $e) {
            $this->assertSame('Falha simulada de um módulo que escuta.', $e->getMessage());
        }

        $matricula->refresh();
        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->estado);
        $this->assertNull($matricula->data_fim);
        $this->assertSame($historicoAntes, MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count());
    }

    public function test_decide_sobre_a_linha_bloqueada_e_nao_sobre_o_model_recebido(): void
    {
        Event::fake([MatriculaEstadoAlterado::class]);
        $desactualizada = $this->novaMatricula(EstadoMatriculaEnum::ACTIVA);
        app(AlterarEstadoMatriculaAction::class)->executar(Matricula::query()->find($desactualizada->id), EstadoMatriculaEnum::CANCELADA);

        try {
            app(AlterarEstadoMatriculaAction::class)->executar($desactualizada, EstadoMatriculaEnum::TRANSFERIDA);
            $this->fail('A matrícula já está Cancelada na base de dados.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('de Cancelada para Transferida', $e->errors()['estado'][0]);
        }

        $this->assertSame(EstadoMatriculaEnum::CANCELADA, $desactualizada->refresh()->estado);
        Event::assertNotDispatched(MatriculaEstadoAlterado::class, fn (MatriculaEstadoAlterado $e) => $e->estadoNovo === EstadoMatriculaEnum::TRANSFERIDA->value);
    }

    public function test_a_data_de_efeito_tem_de_ser_uma_data(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MatriculaEstadoAlterado(1, null, EstadoMatriculaEnum::ACTIVA->value, '15/03/2026', null);
    }
}
