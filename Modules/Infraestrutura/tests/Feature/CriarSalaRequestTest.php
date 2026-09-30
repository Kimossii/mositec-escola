<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Http\Requests\CriarSalaRequest;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class CriarSalaRequestTest extends TestCase
{
    use RefreshDatabase;

    private function validar(array $dados): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($dados, (new CriarSalaRequest())->rules());
    }

    public function test_dados_validos_passam(): void
    {
        $validador = $this->validar([
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
            'capacidade' => 30,
        ]);

        $this->assertFalse($validador->fails());
    }

    public function test_codigo_e_obrigatorio(): void
    {
        $validador = $this->validar([
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_codigo_duplicado_no_mesmo_estabelecimento_falha(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        Sala::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $validador = $this->validar([
            'codigo' => 'A101',
            'nome' => 'Outra Sala',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        $estabelecimentoA = Estabelecimento::create([
            'nome' => 'Escola A',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => false,
        ]);
        Sala::create([
            'estabelecimento_id' => $estabelecimentoA->id,
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $estabelecimentoB = Estabelecimento::create([
            'nome' => 'Escola B',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $validador = $this->validar([
            'codigo' => 'A101',
            'nome' => 'Sala 101 (outra escola)',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $this->assertFalse($validador->fails());
        $this->assertNotSame($estabelecimentoA->id, $estabelecimentoB->id);
    }
}
