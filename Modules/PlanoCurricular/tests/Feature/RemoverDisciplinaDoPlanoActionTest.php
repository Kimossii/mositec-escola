<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\RemoverDisciplinaDoPlanoAction;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class RemoverDisciplinaDoPlanoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_remove_disciplina_do_plano(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT', 'nome' => 'Matemática']);
        $nivelAcademico = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1]);
        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivelAcademico->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        (new RemoverDisciplinaDoPlanoAction())->executar($item);

        $this->assertDatabaseMissing('plano_curricular_disciplinas', ['id' => $item->id]);
    }

    public function test_bloqueia_remocao_de_disciplina_com_periodos_associados(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT', 'nome' => 'Matemática']);
        $nivelAcademico = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1]);
        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivelAcademico->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO]);
        $periodo = Periodo::create(['ano_lectivo_id' => $anoLectivo->id, 'nome' => '1º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2026-02-01', 'data_fim' => '2026-05-01']);
        $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);
        $item->periodosPorAplicacao()->create(['plano_curricular_ano_lectivo_id' => $aplicacao->id, 'periodo_id' => $periodo->id]);

        $this->expectException(ValidationException::class);

        try {
            (new RemoverDisciplinaDoPlanoAction())->executar($item);
        } finally {
            $this->assertDatabaseHas('plano_curricular_disciplinas', ['id' => $item->id]);
        }
    }
}
