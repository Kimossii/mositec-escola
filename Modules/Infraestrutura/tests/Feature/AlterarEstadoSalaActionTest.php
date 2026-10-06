<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Infraestrutura\Actions\AlterarEstadoSalaAction;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class AlterarEstadoSalaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_altera_estado_de_ativa_para_manutencao_e_de_volta(): void
    {
        $sala = Sala::create([
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);
        $this->assertSame(EstadoSala::ATIVA, $sala->estado);

        $emManutencao = (new AlterarEstadoSalaAction())->alterar($sala, EstadoSala::MANUTENCAO);
        $this->assertSame(EstadoSala::MANUTENCAO, $emManutencao->estado);

        $ativaDeNovo = (new AlterarEstadoSalaAction())->alterar($sala, EstadoSala::ATIVA);
        $this->assertSame(EstadoSala::ATIVA, $ativaDeNovo->estado);
    }

    public function test_permite_varias_salas_em_manutencao_ao_mesmo_tempo(): void
    {
        $salaA = Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value]);
        $salaB = Sala::create(['codigo' => 'A102', 'nome' => 'Sala 102', 'tipo' => TipoSala::SALA_AULA->value]);

        $action = new AlterarEstadoSalaAction();
        $action->alterar($salaA, EstadoSala::MANUTENCAO);
        $action->alterar($salaB, EstadoSala::MANUTENCAO);

        $this->assertSame(2, Sala::where('estado', EstadoSala::MANUTENCAO->value)->count());
    }
}
