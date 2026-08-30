<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EncarregadoAlunoRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_encarregado_pode_ter_varios_educandos(): void
    {
        $encarregado = User::create([
            'name' => 'Maria Pais',
            'email' => 'maria@example.com',
            'password' => Hash::make('segredo123'),
        ]);

        $filho1 = User::create(['name' => 'Filho Um', 'numero_matricula' => '2026-0001', 'password' => Hash::make('x')]);
        $filho2 = User::create(['name' => 'Filho Dois', 'numero_matricula' => '2026-0002', 'password' => Hash::make('x')]);

        $encarregado->educandos()->attach([$filho1->id, $filho2->id]);

        $this->assertCount(2, $encarregado->fresh()->educandos);
        $this->assertTrue($filho1->fresh()->encarregados->contains($encarregado));
    }
}
