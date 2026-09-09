<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DadosPessoaIdUnicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_uma_dados_pessoa_nao_pode_ter_dois_users(): void
    {
        $pessoa = DadosPessoal::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI12345',
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);

        User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => Hash::make('x'), 'dados_pessoa_id' => $pessoa->id]);

        $this->expectException(QueryException::class);

        User::create(['name' => 'Ana 2', 'email' => 'ana2@example.com', 'password' => Hash::make('x'), 'dados_pessoa_id' => $pessoa->id]);
    }

    public function test_varios_users_sem_dados_pessoa_sao_permitidos(): void
    {
        User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('x')]);
        User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('x')]);

        $this->assertSame(2, User::count());
    }
}
