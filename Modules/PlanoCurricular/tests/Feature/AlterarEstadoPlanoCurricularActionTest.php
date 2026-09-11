<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\AlterarEstadoPlanoCurricularAction;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Tests\TestCase;

class AlterarEstadoPlanoCurricularActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_desactiva_plano_e_sincroniza_descricao(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);

        $atualizado = (new AlterarEstadoPlanoCurricularAction())->executar($plano, Estado::INATIVO);

        $this->assertSame(Estado::INATIVO->value, $atualizado->estado);
        $this->assertSame('Inativo', $atualizado->estado_descricao);
    }

    public function test_nao_altera_outros_campos(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
            'descricao' => 'Descrição original',
        ]);

        $atualizado = (new AlterarEstadoPlanoCurricularAction())->executar($plano, Estado::INATIVO);

        $this->assertSame('PC1', $atualizado->codigo);
        $this->assertSame('Plano 1', $atualizado->nome);
        $this->assertSame('Descrição original', $atualizado->descricao);
        $this->assertSame($curso->id, $atualizado->curso_id);
        $this->assertSame($estabelecimento->id, $atualizado->estabelecimento_id);
    }
}
