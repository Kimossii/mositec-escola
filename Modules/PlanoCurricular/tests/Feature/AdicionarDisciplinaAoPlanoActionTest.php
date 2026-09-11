<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\AdicionarDisciplinaAoPlanoAction;
use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class AdicionarDisciplinaAoPlanoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_adiciona_disciplina_ao_plano(): void
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
        $nivelAcademico = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $item = (new AdicionarDisciplinaAoPlanoAction())->executar($plano, new PlanoCurricularDisciplinaDTO(
            disciplina_id: $disciplina->id,
            nivel_academico_id: $nivelAcademico->id,
            tipo: TipoDisciplinaPlano::NORMAL,
            obrigatoria: true,
            ordem: 1,
            carga_horaria: 90,
            creditos: 4,
            componente: ComponentePlanoCurricular::GERAL,
        ));

        $this->assertSame($plano->id, $item->plano_curricular_id);
        $this->assertSame($disciplina->id, $item->disciplina_id);
        $this->assertSame($nivelAcademico->id, $item->nivel_academico_id);
        $this->assertSame(90, $item->carga_horaria);
        $this->assertSame(4, $item->creditos);
        $this->assertSame(ComponentePlanoCurricular::GERAL, $item->componente);
        $this->assertSame(TipoDisciplinaPlano::NORMAL, $item->tipo);
        $this->assertTrue($item->obrigatoria);
        $this->assertSame(1, $item->ordem);
        $this->assertSame('Geral', $item->componente_descricao);
        $this->assertSame('Normal', $item->tipo_descricao);
    }
}
