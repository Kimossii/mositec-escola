<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class PlanoCurricularDisciplinaModelTest extends TestCase
{
    use RefreshDatabase;

    private function contexto(): array
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 2, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso Técnico']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano 1']);

        return compact('estabelecimento', 'curso', 'plano', 'nivel');
    }

    public function test_associa_disciplina_ao_plano(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D1', 'nome' => 'Electrotecnia']);

        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
            'carga_horaria' => 90,
            'componente' => ComponentePlanoCurricular::TECNICA->value,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        $this->assertSame('Técnica', $item->fresh()->componente_descricao);
        $this->assertSame('Normal', $item->fresh()->tipo_descricao);
        $this->assertTrue($item->obrigatoria);
        $this->assertSame($disciplina->id, $item->disciplina->id);
    }

    public function test_impede_duplicar_mesma_disciplina_no_plano(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D1', 'nome' => 'Matemática']);

        PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'tipo' => 0, 'ordem' => 1,
        ]);

        $this->expectException(QueryException::class);
        PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'tipo' => 0, 'ordem' => 2,
        ]);
    }

    public function test_permite_disciplina_optativa_nao_obrigatoria(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D2', 'nome' => 'Optativa X']);

        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id,
            'tipo' => TipoDisciplinaPlano::OPTATIVA->value, 'obrigatoria' => false, 'ordem' => 1,
        ]);

        $this->assertFalse($item->obrigatoria);
        $this->assertSame(TipoDisciplinaPlano::OPTATIVA, $item->tipo);
    }

    public function test_creditos_e_componente_sao_nullable(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D3', 'nome' => 'Língua Portuguesa']);

        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'ordem' => 1,
        ]);

        $this->assertNull($item->creditos);
        $this->assertNull($item->componente);
        $this->assertNull($item->fresh()->componente_descricao);
    }

    public function test_nao_possui_coluna_semestre(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('plano_curricular_disciplinas', 'semestre'));
    }

    public function test_nao_possui_coluna_nivel_academico_id(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('plano_curricular_disciplinas', 'nivel_academico_id'));
    }
}
