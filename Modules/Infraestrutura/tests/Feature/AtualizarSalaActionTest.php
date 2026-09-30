<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Infraestrutura\Actions\AtualizarSalaAction;
use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class AtualizarSalaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualiza_dados_da_sala(): void
    {
        $sala = Sala::create([
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $atualizada = (new AtualizarSalaAction())->atualizar($sala, new SalaDTO(
            codigo: 'A101',
            nome: 'Sala 101 - Bloco A',
            tipo: TipoSala::LABORATORIO,
            capacidade: 25,
            localizacao: 'Bloco A, 1º Andar',
            estado: EstadoSala::MANUTENCAO,
        ));

        $this->assertSame('Sala 101 - Bloco A', $atualizada->nome);
        $this->assertSame(TipoSala::LABORATORIO, $atualizada->tipo);
        $this->assertSame(25, $atualizada->capacidade);
        $this->assertSame('Bloco A, 1º Andar', $atualizada->localizacao);
        $this->assertSame(EstadoSala::MANUTENCAO, $atualizada->estado);
    }
}
