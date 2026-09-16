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
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

class AlterarEstadoInscricaoDisciplinaActionTest extends TestCase
{
    use RefreshDatabase;

    // Constrói a Matrícula directamente no modelo (ver Task 2) — este teste
    // verifica AlterarEstadoInscricaoDisciplinaAction em isolamento.
    private function criarInscricao(): InscricaoDisciplina
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

        return app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);
    }

    public function test_conclui_inscricao_e_preenche_data_conclusao(): void
    {
        $inscricao = $this->criarInscricao();

        $actualizada = app(AlterarEstadoInscricaoDisciplinaAction::class)->executar($inscricao, EstadoInscricaoDisciplinaEnum::CONCLUIDA);

        $this->assertSame(EstadoInscricaoDisciplinaEnum::CONCLUIDA, $actualizada->estado);
        $this->assertSame(now()->toDateString(), $actualizada->data_conclusao->toDateString());
    }

    public function test_rejeita_transicao_de_estado_terminal(): void
    {
        $inscricao = $this->criarInscricao();
        $inscricao = app(AlterarEstadoInscricaoDisciplinaAction::class)->executar($inscricao, EstadoInscricaoDisciplinaEnum::REPROVADA);

        $this->expectException(ValidationException::class);

        app(AlterarEstadoInscricaoDisciplinaAction::class)->executar($inscricao, EstadoInscricaoDisciplinaEnum::CONCLUIDA);
    }
}
