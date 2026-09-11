<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\AtualizarPlanoCurricularAction;
use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Tests\TestCase;

class AtualizarPlanoCurricularActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualiza_curso_codigo_nome_e_descricao(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $outroCurso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'GES', 'nome' => 'Gestão']);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);

        $atualizado = (new AtualizarPlanoCurricularAction())->executar($plano, new PlanoCurricularDTO(
            curso_id: $outroCurso->id,
            codigo: 'PC2',
            nome: 'Plano 2',
            descricao: 'Descrição nova',
        ));

        $this->assertSame($outroCurso->id, $atualizado->curso_id);
        $this->assertSame('PC2', $atualizado->codigo);
        $this->assertSame('Plano 2', $atualizado->nome);
        $this->assertSame('Descrição nova', $atualizado->descricao);
        $this->assertSame($estabelecimento->id, $atualizado->estabelecimento_id);
    }

    public function test_nao_altera_estabelecimento_id_nem_estado(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);

        $atualizado = (new AtualizarPlanoCurricularAction())->executar($plano, new PlanoCurricularDTO(
            curso_id: $curso->id,
            codigo: 'PC1',
            nome: 'Plano 1 renomeado',
        ));

        $this->assertSame($estabelecimento->id, $atualizado->estabelecimento_id);
        $this->assertSame($plano->estado, $atualizado->estado);
    }
}
