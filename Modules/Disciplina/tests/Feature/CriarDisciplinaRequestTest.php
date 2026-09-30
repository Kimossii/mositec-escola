<?php

namespace Modules\Disciplina\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Disciplina\Http\Requests\CriarDisciplinaRequest;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class CriarDisciplinaRequestTest extends TestCase
{
    use RefreshDatabase;

    private function validar(array $dados): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($dados, (new CriarDisciplinaRequest())->rules());
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

    public function test_codigo_duplicado_no_mesmo_estabelecimento_falha(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $validador = $this->validar(['codigo' => 'INF', 'nome' => 'Outro Nome']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_nome_duplicado_no_mesmo_estabelecimento_falha(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $validador = $this->validar(['codigo' => 'OUTRO', 'nome' => 'Informática']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('nome', $validador->errors()->toArray());
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);
        Disciplina::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        Estabelecimento::create(['nome' => 'Escola B', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);

        $validador = $this->validar(['codigo' => 'INF', 'nome' => 'Informática (outra escola)']);

        $this->assertFalse($validador->fails());
    }
}
