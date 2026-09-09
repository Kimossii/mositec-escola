<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Actions\AtualizarCursoAction;
use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class AtualizarCursoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualiza_codigo_nome_e_descricao(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $atualizado = (new AtualizarCursoAction())->executar($curso, new CursoDTO(
            codigo: 'INF2',
            nome: 'Informática e Sistemas',
            descricao: 'Descrição nova',
        ));

        $this->assertSame('INF2', $atualizado->codigo);
        $this->assertSame('Informática e Sistemas', $atualizado->nome);
        $this->assertSame('Descrição nova', $atualizado->descricao);
    }
}
