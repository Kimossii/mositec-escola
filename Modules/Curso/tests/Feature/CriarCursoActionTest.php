<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Curso\Actions\CriarCursoAction;
use Modules\Curso\DTO\CursoDTO;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class CriarCursoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_curso_associado_ao_estabelecimento_activo_com_estado_ativo_por_defeito(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $curso = (new CriarCursoAction())->executar(new CursoDTO(
            codigo: 'INF',
            nome: 'Informática',
            descricao: 'Curso técnico de Informática',
        ));

        $this->assertSame($estabelecimento->id, $curso->estabelecimento_id);
        $this->assertSame(Estado::ATIVO->value, $curso->estado);
        $this->assertSame('INF', $curso->codigo);
        $this->assertSame('Curso técnico de Informática', $curso->descricao);
    }
}
