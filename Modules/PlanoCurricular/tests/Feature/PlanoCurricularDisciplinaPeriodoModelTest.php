<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplinaPeriodo;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class PlanoCurricularDisciplinaPeriodoModelTest extends TestCase
{
    use RefreshDatabase;

    private function contexto(): array
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $disciplinaRegistro = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D1', 'nome' => 'Matemática']);
        $disciplinaDoPlano = $plano->disciplinas()->create([
            'disciplina_id' => $disciplinaRegistro->id, 'tipo' => 0, 'ordem' => 1,
        ]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO]);
        $periodo1 = Periodo::create(['ano_lectivo_id' => $anoLectivo->id, 'nome' => '1º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2026-02-01', 'data_fim' => '2026-05-01']);
        $periodo2 = Periodo::create(['ano_lectivo_id' => $anoLectivo->id, 'nome' => '2º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 2, 'data_inicio' => '2026-05-02', 'data_fim' => '2026-08-01']);
        $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);

        return compact('estabelecimento', 'curso', 'plano', 'disciplinaDoPlano', 'anoLectivo', 'periodo1', 'periodo2', 'aplicacao');
    }

    public function test_associa_disciplina_a_um_periodo(): void
    {
        ['aplicacao' => $aplicacao, 'disciplinaDoPlano' => $disciplinaDoPlano, 'periodo1' => $periodo1] = $this->contexto();

        $item = PlanoCurricularDisciplinaPeriodo::create([
            'plano_curricular_ano_lectivo_id' => $aplicacao->id,
            'plano_curricular_disciplina_id' => $disciplinaDoPlano->id,
            'periodo_id' => $periodo1->id,
        ]);

        $this->assertSame($aplicacao->id, $item->planoCurricularAnoLectivo->id);
        $this->assertSame($disciplinaDoPlano->id, $item->planoCurricularDisciplina->id);
        $this->assertSame($periodo1->id, $item->periodo->id);
    }

    public function test_associa_disciplina_a_varios_periodos(): void
    {
        ['aplicacao' => $aplicacao, 'disciplinaDoPlano' => $disciplinaDoPlano, 'periodo1' => $periodo1, 'periodo2' => $periodo2] = $this->contexto();

        PlanoCurricularDisciplinaPeriodo::create(['plano_curricular_ano_lectivo_id' => $aplicacao->id, 'plano_curricular_disciplina_id' => $disciplinaDoPlano->id, 'periodo_id' => $periodo1->id]);
        PlanoCurricularDisciplinaPeriodo::create(['plano_curricular_ano_lectivo_id' => $aplicacao->id, 'plano_curricular_disciplina_id' => $disciplinaDoPlano->id, 'periodo_id' => $periodo2->id]);

        $this->assertCount(2, $disciplinaDoPlano->periodosPorAplicacao);
    }

    public function test_constraint_de_unicidade_impede_duplicar_mesmo_triplo(): void
    {
        ['aplicacao' => $aplicacao, 'disciplinaDoPlano' => $disciplinaDoPlano, 'periodo1' => $periodo1] = $this->contexto();

        PlanoCurricularDisciplinaPeriodo::create(['plano_curricular_ano_lectivo_id' => $aplicacao->id, 'plano_curricular_disciplina_id' => $disciplinaDoPlano->id, 'periodo_id' => $periodo1->id]);

        $this->expectException(QueryException::class);
        PlanoCurricularDisciplinaPeriodo::create(['plano_curricular_ano_lectivo_id' => $aplicacao->id, 'plano_curricular_disciplina_id' => $disciplinaDoPlano->id, 'periodo_id' => $periodo1->id]);
    }

    public function test_mesma_disciplina_em_aplicacoes_de_anos_diferentes_e_independente(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano, 'disciplinaDoPlano' => $disciplinaDoPlano, 'periodo1' => $periodo1] = $this->contexto();

        $anoSeguinte = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2027/2028', 'data_inicio' => '2027-02-01', 'data_fim' => '2027-11-30', 'estado' => EstadoAnoLectivo::ATIVO]);
        $periodoSeguinte = Periodo::create(['ano_lectivo_id' => $anoSeguinte->id, 'nome' => '1º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2027-02-01', 'data_fim' => '2027-05-01']);
        $aplicacaoSeguinte = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoSeguinte->id]);

        PlanoCurricularDisciplinaPeriodo::create(['plano_curricular_ano_lectivo_id' => $aplicacaoSeguinte->id, 'plano_curricular_disciplina_id' => $disciplinaDoPlano->id, 'periodo_id' => $periodoSeguinte->id]);

        $this->assertCount(0, $disciplinaDoPlano->periodosPorAplicacao()->where('plano_curricular_ano_lectivo_id', '!=', $aplicacaoSeguinte->id)->get());
        $this->assertCount(1, $disciplinaDoPlano->periodosPorAplicacao()->where('plano_curricular_ano_lectivo_id', $aplicacaoSeguinte->id)->get());
    }
}
