<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class SalaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_sala_com_casts_e_relacao_com_estabelecimento(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $sala = Sala::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
            'capacidade' => 30,
            'estado' => EstadoSala::ATIVA->value,
        ]);

        $this->assertInstanceOf(TipoSala::class, $sala->tipo);
        $this->assertInstanceOf(EstadoSala::class, $sala->estado);
        $this->assertSame(30, $sala->capacidade);
        $this->assertTrue($sala->estabelecimento->is($estabelecimento));
    }

    public function test_sincroniza_tipo_descricao_e_estado_descricao_ao_gravar(): void
    {
        $sala = Sala::create([
            'codigo' => 'LAB-01',
            'nome' => 'Laboratório de Informática',
            'tipo' => TipoSala::LABORATORIO->value,
            'estado' => EstadoSala::MANUTENCAO->value,
        ]);

        $this->assertSame('Laboratório', $sala->fresh()->tipo_descricao);
        $this->assertSame('Em Manutenção', $sala->fresh()->estado_descricao);
    }

    public function test_eliminar_sala_e_soft_delete(): void
    {
        $sala = Sala::create([
            'codigo' => 'B02',
            'nome' => 'Biblioteca',
            'tipo' => TipoSala::BIBLIOTECA->value,
        ]);

        $sala->delete();

        $this->assertSoftDeleted('salas', ['id' => $sala->id]);
    }
}
