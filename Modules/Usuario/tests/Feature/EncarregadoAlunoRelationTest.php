<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\DadosPessoa;
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

        $filho1 = DadosPessoa::create(['nome_completo' => 'Filho Um', 'numero_identificacao' => 'BI-1', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $filho2 = DadosPessoa::create(['nome_completo' => 'Filho Dois', 'numero_identificacao' => 'BI-2', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        $encarregado->educandos()->attach([$filho1->id, $filho2->id]);

        $this->assertCount(2, $encarregado->fresh()->educandos);
        $this->assertTrue($filho1->fresh()->encarregados->contains($encarregado));
    }
}
