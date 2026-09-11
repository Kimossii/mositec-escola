<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Http\Requests\AdicionarDisciplinaRequest;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class AdicionarDisciplinaRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejeita_disciplina_de_outro_estabelecimento(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => false]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoA->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1]);
        $disciplinaDeOutroEstabelecimento = Disciplina::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'D1', 'nome' => 'Matemática']);

        $request = new AdicionarDisciplinaRequest;
        $request->setRouteResolver(fn () => tap(new Route('POST', '/x', []), fn ($r) => $r->bind(new Request)->setParameter('planoCurricular', $plano)));

        $validator = Validator::make(
            ['disciplina_id' => $disciplinaDeOutroEstabelecimento->id, 'nivel_academico_id' => $nivel->id, 'tipo' => 0, 'obrigatoria' => true, 'ordem' => 1],
            $request->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('disciplina_id', $validator->errors()->toArray());
    }
}
