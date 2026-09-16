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
use Modules\Matricula\Actions\AlterarEstadoInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Actions\EliminarInscricaoDisciplinaAction;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class EliminarInscricaoDisciplinaActionTest extends TestCase
{
    use RefreshDatabase;

    // Constrói a Matrícula directamente no modelo (ver Task 2) — este teste
    // verifica EliminarInscricaoDisciplinaAction em isolamento.
    private function criarInscricao(): InscricaoDisciplina
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
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

        return app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);
    }

    public function test_elimina_inscricao_ainda_inscrita(): void
    {
        $inscricao = $this->criarInscricao();

        app(EliminarInscricaoDisciplinaAction::class)->executar($inscricao);

        $this->assertSoftDeleted('inscricoes_disciplinas', ['id' => $inscricao->id]);
    }

    public function test_rejeita_eliminar_inscricao_concluida(): void
    {
        $inscricao = $this->criarInscricao();
        $inscricao = app(AlterarEstadoInscricaoDisciplinaAction::class)->executar($inscricao, EstadoInscricaoDisciplinaEnum::CONCLUIDA);

        $this->expectException(ValidationException::class);

        app(EliminarInscricaoDisciplinaAction::class)->executar($inscricao);
    }
}
