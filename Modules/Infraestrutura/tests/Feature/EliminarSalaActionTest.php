<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Infraestrutura\Actions\EliminarSalaAction;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class EliminarSalaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_elimina_sala_com_soft_delete(): void
    {
        $sala = Sala::create([
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        (new EliminarSalaAction())->executar($sala);

        $this->assertSoftDeleted('salas', ['id' => $sala->id]);
        $this->assertSame(0, Sala::count());
        $this->assertSame(1, Sala::withTrashed()->count());
    }
}
