<?php

namespace Modules\Disciplina\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Disciplina\Actions\CriarDisciplinaAction;
use Modules\Disciplina\DTO\DisciplinaDTO;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class CriarDisciplinaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_disciplina_associado_ao_estabelecimento_activo_com_estado_ativo_por_defeito(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $disciplina = (new CriarDisciplinaAction())->executar(new DisciplinaDTO(
            codigo: 'INF',
            nome: 'Informática',
            descricao: 'Disciplina técnico de Informática',
        ));

        $this->assertSame($estabelecimento->id, $disciplina->estabelecimento_id);
        $this->assertSame(Estado::ATIVO->value, $disciplina->estado);
        $this->assertSame('INF', $disciplina->codigo);
        $this->assertSame('Disciplina técnico de Informática', $disciplina->descricao);
    }
}
