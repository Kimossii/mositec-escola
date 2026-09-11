<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\CriarPlanoCurricularAction;
use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Tests\TestCase;

class CriarPlanoCurricularActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_plano_com_estabelecimento_actual(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);

        $plano = (new CriarPlanoCurricularAction())->executar(new PlanoCurricularDTO(
            curso_id: $curso->id, codigo: 'PC1', nome: 'Plano 1', descricao: 'Descrição',
        ));

        $this->assertSame($estabelecimento->id, $plano->estabelecimento_id);
        $this->assertSame($curso->id, $plano->curso_id);
        $this->assertSame('PC1', $plano->codigo);
    }
}
