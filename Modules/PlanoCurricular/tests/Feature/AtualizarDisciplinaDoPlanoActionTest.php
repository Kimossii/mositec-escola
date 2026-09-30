<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\AtualizarDisciplinaDoPlanoAction;
use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class AtualizarDisciplinaDoPlanoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualiza_campos_da_disciplina_do_plano(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivelAcademico = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivelAcademico->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT', 'nome' => 'Matemática']);
        $outraDisciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'FIS', 'nome' => 'Física']);
        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
            'carga_horaria' => 90,
            'creditos' => 4,
            'componente' => ComponentePlanoCurricular::GERAL->value,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        $atualizado = (new AtualizarDisciplinaDoPlanoAction())->executar($item, new PlanoCurricularDisciplinaDTO(
            disciplina_id: $outraDisciplina->id,
            tipo: TipoDisciplinaPlano::OPTATIVA,
            obrigatoria: false,
            ordem: 2,
            carga_horaria: 60,
            creditos: 3,
            componente: ComponentePlanoCurricular::TECNICA,
        ));

        $this->assertSame($outraDisciplina->id, $atualizado->disciplina_id);
        $this->assertSame(60, $atualizado->carga_horaria);
        $this->assertSame(3, $atualizado->creditos);
        $this->assertSame(ComponentePlanoCurricular::TECNICA, $atualizado->componente);
        $this->assertSame(TipoDisciplinaPlano::OPTATIVA, $atualizado->tipo);
        $this->assertFalse($atualizado->obrigatoria);
        $this->assertSame(2, $atualizado->ordem);
        $this->assertSame('Técnica', $atualizado->componente_descricao);
        $this->assertSame('Optativa', $atualizado->tipo_descricao);
        $this->assertSame($plano->id, $atualizado->plano_curricular_id);
    }
}
