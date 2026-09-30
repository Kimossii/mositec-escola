<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Curso\Actions\AlterarEstadoCursoAction;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class AlterarEstadoCursoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_desactiva_curso_e_sincroniza_descricao(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $atualizado = (new AlterarEstadoCursoAction())->executar($curso, Estado::INATIVO);

        $this->assertSame(Estado::INATIVO->value, $atualizado->estado);
        $this->assertSame('Inativo', $atualizado->estado_descricao);
    }
}
