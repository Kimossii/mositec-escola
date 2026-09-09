<?php

namespace Modules\Disciplina\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Enums\Estado;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DisciplinaModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
    }

    public function test_criar_disciplina_regista_autoria_e_sincroniza_estado_descricao(): void
    {
        $user = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($user);
        $estabelecimento = $this->criarEstabelecimento();

        $disciplina = Disciplina::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => 'INF',
            'nome' => 'Informática',
        ]);

        $this->assertSame($user->id, $disciplina->criado_por);
        $this->assertSame($user->id, $disciplina->editado_por);
        $this->assertSame(Estado::ATIVO->value, $disciplina->estado);
        $this->assertSame('Ativo', $disciplina->estado_descricao);
    }

    public function test_atualizar_estado_sincroniza_descricao_e_editado_por(): void
    {
        $criador = User::create(['name' => 'Criador', 'email' => 'criador@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($criador);
        $estabelecimento = $this->criarEstabelecimento();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $editor = User::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($editor);
        $disciplina->update(['estado' => Estado::INATIVO->value]);

        $disciplina->refresh();
        $this->assertSame(Estado::INATIVO->value, $disciplina->estado);
        $this->assertSame('Inativo', $disciplina->estado_descricao);
        $this->assertSame($editor->id, $disciplina->editado_por);
        $this->assertSame($criador->id, $disciplina->criado_por);
    }

    public function test_codigo_duplicado_no_mesmo_estabelecimento_viola_constraint(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->expectException(QueryException::class);
        Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Outro Nome']);
    }

    public function test_nome_duplicado_no_mesmo_estabelecimento_viola_constraint(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->expectException(QueryException::class);
        Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'OUTRO', 'nome' => 'Informática']);
    }

    public function test_mesmo_codigo_e_nome_permitido_em_estabelecimentos_diferentes(): void
    {
        $estabelecimentoA = $this->criarEstabelecimento();
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);

        $disciplinaA = Disciplina::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $disciplinaB = Disciplina::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->assertNotSame($disciplinaA->id, $disciplinaB->id);
    }
}
