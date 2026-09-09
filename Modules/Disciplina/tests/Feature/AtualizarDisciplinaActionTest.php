<?php

namespace Modules\Disciplina\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Disciplina\Actions\AtualizarDisciplinaAction;
use Modules\Disciplina\DTO\DisciplinaDTO;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class AtualizarDisciplinaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualiza_codigo_nome_e_descricao(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $atualizado = (new AtualizarDisciplinaAction())->executar($disciplina, new DisciplinaDTO(
            codigo: 'INF2',
            nome: 'Informática e Sistemas',
            descricao: 'Descrição nova',
        ));

        $this->assertSame('INF2', $atualizado->codigo);
        $this->assertSame('Informática e Sistemas', $atualizado->nome);
        $this->assertSame('Descrição nova', $atualizado->descricao);
    }
}
