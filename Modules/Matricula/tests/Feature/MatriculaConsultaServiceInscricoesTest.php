<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\MatriculaConsultaService;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class MatriculaConsultaServiceInscricoesTest extends TestCase
{
    use RefreshDatabase;

    // Constrói a Matrícula directamente no modelo (ver Task 2) — este teste
    // verifica só a consulta, sem depender de currículo confirmado.
    public function test_lista_disciplinas_da_matricula_com_nome_da_disciplina_carregado(): void
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
        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);

        $lista = app(MatriculaConsultaService::class)->listarDisciplinasDaMatricula($matricula);

        $this->assertCount(1, $lista);
        $this->assertSame('Matemática I', $lista->first()->planoCurricularDisciplina->disciplina->nome);
    }
}
