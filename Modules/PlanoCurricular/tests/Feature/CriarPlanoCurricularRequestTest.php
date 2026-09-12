<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class CriarPlanoCurricularRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejeita_curso_de_outro_estabelecimento(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => false]);
        $cursoDeOutroEstabelecimento = Curso::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'C1', 'nome' => 'Curso B']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $validator = Validator::make(
            ['curso_id' => $cursoDeOutroEstabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('curso_id', $validator->errors()->toArray());
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $validator = Validator::make(
            ['curso_id' => $curso->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertFalse($validator->fails());
    }

    public function test_aceita_sem_curso_id(): void
    {
        Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'CR', 'nome' => 'Creche I', 'ordem' => 1, 'etapa_ensino' => 1]);

        $validator = Validator::make(
            ['nivel_academico_id' => $nivel->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertFalse($validator->fails());
    }

    public function test_rejeita_sem_nivel_academico_id(): void
    {
        Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'C1', 'nome' => 'Curso A']);

        $validator = Validator::make(
            ['curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('nivel_academico_id', $validator->errors()->toArray());
    }

    public function test_rejeita_nivel_academico_de_outro_estabelecimento(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => false]);
        $nivelDeOutroEstabelecimento = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $validator = Validator::make(
            ['nivel_academico_id' => $nivelDeOutroEstabelecimento->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('nivel_academico_id', $validator->errors()->toArray());
    }
}
