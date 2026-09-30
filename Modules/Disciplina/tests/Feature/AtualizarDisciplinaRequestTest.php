<?php

namespace Modules\Disciplina\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Disciplina\Http\Requests\AtualizarDisciplinaRequest;
use Tests\TestCase;

class AtualizarDisciplinaRequestTest extends TestCase
{
    use RefreshDatabase;

    private function validar(array $dados): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($dados, (new AtualizarDisciplinaRequest())->rules());
    }

    public function test_dados_validos_passam(): void
    {
        $validador = $this->validar(['codigo' => 'INF', 'nome' => 'Informática']);

        $this->assertFalse($validador->fails());
    }

    public function test_codigo_e_obrigatorio(): void
    {
        $validador = $this->validar(['nome' => 'Informática']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_nome_e_obrigatorio(): void
    {
        $validador = $this->validar(['codigo' => 'INF']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('nome', $validador->errors()->toArray());
    }
}
