<?php

namespace Modules\Disciplina\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Disciplina\Actions\AlterarEstadoDisciplinaAction;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class AlterarEstadoDisciplinaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_desactiva_disciplina_e_sincroniza_descricao(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $atualizado = (new AlterarEstadoDisciplinaAction())->executar($disciplina, Estado::INATIVO);

        $this->assertSame(Estado::INATIVO->value, $atualizado->estado);
        $this->assertSame('Inativo', $atualizado->estado_descricao);
    }
}
