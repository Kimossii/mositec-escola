<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\CriarPlanoCurricularAction;
use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class CriarPlanoCurricularActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_plano_com_estabelecimento_actual(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $plano = (new CriarPlanoCurricularAction())->executar(new PlanoCurricularDTO(
            nivel_academico_id: $nivel->id, codigo: 'PC1', nome: 'Plano 1', curso_id: $curso->id, descricao: 'Descrição',
        ));

        $this->assertSame($estabelecimento->id, $plano->estabelecimento_id);
        $this->assertSame($curso->id, $plano->curso_id);
        $this->assertSame($nivel->id, $plano->nivel_academico_id);
        $this->assertSame('PC1', $plano->codigo);
    }

    public function test_cria_plano_sem_curso(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CR', 'nome' => 'Creche I', 'ordem' => 1, 'etapa_ensino' => 1]);

        $plano = (new CriarPlanoCurricularAction())->executar(new PlanoCurricularDTO(
            nivel_academico_id: $nivel->id, codigo: 'PC-CR', nome: 'Plano Creche',
        ));

        $this->assertNull($plano->curso_id);
        $this->assertSame($nivel->id, $plano->nivel_academico_id);
    }
}
