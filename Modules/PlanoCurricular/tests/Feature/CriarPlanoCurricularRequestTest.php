<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;
use Tests\TestCase;

class CriarPlanoCurricularRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejeita_curso_de_outro_estabelecimento(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => false]);
        $cursoDeOutroEstabelecimento = Curso::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'C1', 'nome' => 'Curso B']);

        $validator = Validator::make(
            ['curso_id' => $cursoDeOutroEstabelecimento->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('curso_id', $validator->errors()->toArray());
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'C1', 'nome' => 'Curso A']);

        $validator = Validator::make(
            ['curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertFalse($validator->fails());
    }
}
