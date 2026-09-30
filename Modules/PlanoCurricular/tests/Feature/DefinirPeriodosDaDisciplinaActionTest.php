<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\DefinirPeriodosDaDisciplinaAction;
use Modules\PlanoCurricular\DTO\DefinirPeriodosDisciplinaDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class DefinirPeriodosDaDisciplinaActionTest extends TestCase
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
        $periodo3 = Periodo::create(['ano_lectivo_id' => $anoLectivo->id, 'nome' => '3º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 3, 'data_inicio' => '2026-08-02', 'data_fim' => '2026-11-30']);
        $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);

        return compact('disciplinaDoPlano', 'periodo1', 'periodo2', 'periodo3', 'aplicacao');
    }

    public function test_associa_disciplina_a_varios_periodos_de_uma_vez(): void
    {
        ['aplicacao' => $aplicacao, 'disciplinaDoPlano' => $disciplinaDoPlano, 'periodo1' => $periodo1, 'periodo2' => $periodo2] = $this->contexto();

        (new DefinirPeriodosDaDisciplinaAction())->executar($aplicacao, $disciplinaDoPlano, new DefinirPeriodosDisciplinaDTO([$periodo1->id, $periodo2->id]));

        $this->assertCount(2, $disciplinaDoPlano->periodosPorAplicacao()->where('plano_curricular_ano_lectivo_id', $aplicacao->id)->get());
    }

    public function test_substitui_completamente_o_conjunto_anterior(): void
    {
        ['aplicacao' => $aplicacao, 'disciplinaDoPlano' => $disciplinaDoPlano, 'periodo1' => $periodo1, 'periodo2' => $periodo2, 'periodo3' => $periodo3] = $this->contexto();
        $action = new DefinirPeriodosDaDisciplinaAction();

        $action->executar($aplicacao, $disciplinaDoPlano, new DefinirPeriodosDisciplinaDTO([$periodo1->id, $periodo2->id]));
        $action->executar($aplicacao, $disciplinaDoPlano, new DefinirPeriodosDisciplinaDTO([$periodo3->id]));

        $periodosRestantes = $disciplinaDoPlano->periodosPorAplicacao()->where('plano_curricular_ano_lectivo_id', $aplicacao->id)->pluck('periodo_id');
        $this->assertSame([$periodo3->id], $periodosRestantes->all());
    }

    public function test_substituir_por_conjunto_vazio_remove_todos(): void
    {
        ['aplicacao' => $aplicacao, 'disciplinaDoPlano' => $disciplinaDoPlano, 'periodo1' => $periodo1] = $this->contexto();
        $action = new DefinirPeriodosDaDisciplinaAction();

        $action->executar($aplicacao, $disciplinaDoPlano, new DefinirPeriodosDisciplinaDTO([$periodo1->id]));
        $action->executar($aplicacao, $disciplinaDoPlano, new DefinirPeriodosDisciplinaDTO([]));

        $this->assertCount(0, $disciplinaDoPlano->periodosPorAplicacao()->where('plano_curricular_ano_lectivo_id', $aplicacao->id)->get());
    }
}
