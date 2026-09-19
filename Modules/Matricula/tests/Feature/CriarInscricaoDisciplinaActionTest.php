<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

class CriarInscricaoDisciplinaActionTest extends TestCase
{
    use RefreshDatabase;

    // Constrói a Matrícula directamente no modelo, sem passar por
    // CriarMatriculaAction — este teste verifica CriarInscricaoDisciplinaAction
    // em isolamento, sem depender de currículo confirmado nem do hook de
    // inscrição automática que CriarMatriculaAction ganha na Task 4.
    private function criarCenario(): array
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001',
            'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        return [$matricula, $planoDisciplina];
    }

    public function test_cria_inscricao_inscrita(): void
    {
        [$matricula, $planoDisciplina] = $this->criarCenario();

        $inscricao = app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);

        $this->assertSame(EstadoInscricaoDisciplinaEnum::INSCRITA, $inscricao->estado);
        $this->assertSame($matricula->id, $inscricao->matricula_id);
    }

    public function test_rejeita_inscricao_duplicada_na_mesma_disciplina(): void
    {
        [$matricula, $planoDisciplina] = $this->criarCenario();
        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);

        $this->expectException(ValidationException::class);

        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);
    }

    public function test_rejeita_inscricao_em_matricula_concluida(): void
    {
        [$matricula, $planoDisciplina] = $this->criarCenario();
        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CONCLUIDA);

        $this->expectException(ValidationException::class);

        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);
    }

    public function test_rejeita_inscricao_duplicada_em_disciplina_diferente_matricula(): void
    {
        [$matricula, $planoDisciplina] = $this->criarCenario();

        // Inscreve o aluno na primeira matrícula
        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);

        // Cria uma segunda matrícula para o MESMO aluno
        $estabelecimento = $matricula->aluno->estabelecimento;
        $anoLectivo = $matricula->anoLectivo;
        $nivel = $matricula->turma->nivelAcademico;
        $turma2 = Turma::create([
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T2',
            'nome' => 'Turma 2',
        ]);

        $matricula2 = Matricula::create([
            'aluno_id' => $matricula->aluno_id,
            'turma_id' => $turma2->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0002',
            'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        // Tenta inscrever a segunda matrícula na MESMA disciplina (via mesmo planoDisciplina)
        // Deve falhar porque o aluno já está inscrito nesta disciplina (via primeira matrícula)
        $this->expectException(ValidationException::class);

        app(CriarInscricaoDisciplinaAction::class)->executar($matricula2, $planoDisciplina);
    }

    public function test_permite_reinscricao_quando_matricula_antiga_esta_encerrada(): void
    {
        [$matricula, $planoDisciplina] = $this->criarCenario();

        // Inscreve o aluno na primeira matrícula
        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);

        // Encerra a primeira matrícula (PENDENTE -> ACTIVA -> CONCLUIDA)
        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CONCLUIDA);

        // Cria uma segunda matrícula para o MESMO aluno (ex.: repetência)
        $anoLectivo = $matricula->anoLectivo;
        $nivel = $matricula->turma->nivelAcademico;
        $turma2 = Turma::create([
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T2',
            'nome' => 'Turma 2',
        ]);

        $matricula2 = Matricula::create([
            'aluno_id' => $matricula->aluno_id,
            'turma_id' => $turma2->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0002',
            'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        // Inscrever a segunda matrícula na MESMA disciplina deve SUCEDER, porque a
        // matrícula antiga que continha a inscrição já está encerrada (CONCLUIDA).
        $inscricao = app(CriarInscricaoDisciplinaAction::class)->executar($matricula2, $planoDisciplina);

        $this->assertSame(EstadoInscricaoDisciplinaEnum::INSCRITA, $inscricao->estado);
        $this->assertSame($matricula2->id, $inscricao->matricula_id);
    }
}
