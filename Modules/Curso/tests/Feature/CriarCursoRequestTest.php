<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Curso\Http\Requests\CriarCursoRequest;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class CriarCursoRequestTest extends TestCase
{
    use RefreshDatabase;

    private function validar(array $dados): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($dados, (new CriarCursoRequest())->rules());
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
        $estabelecimento = $this->estabelecimentoDeTeste(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value]);
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $validador = $this->validar(['codigo' => 'INF', 'nome' => 'Outro Nome']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_nome_duplicado_no_mesmo_estabelecimento_falha(): void
    {
        $estabelecimento = $this->estabelecimentoDeTeste(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value]);
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $validador = $this->validar(['codigo' => 'OUTRO', 'nome' => 'Informática']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('nome', $validador->errors()->toArray());
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        $estabelecimentoA = $this->estabelecimentoDeOutroTenant(['nome' => 'Escola A', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value]);
        $this->noTenantDe($estabelecimentoA, fn () => Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'INF', 'nome' => 'Informática']));

        $this->estabelecimentoDeTeste(['nome' => 'Escola B', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value]);

        $validador = $this->validar(['codigo' => 'INF', 'nome' => 'Informática (outra escola)']);

        $this->assertFalse($validador->fails());
    }
}
