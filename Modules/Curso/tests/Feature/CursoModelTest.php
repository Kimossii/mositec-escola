<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CursoModelTest extends TestCase
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

    public function test_criar_curso_regista_autoria_e_sincroniza_estado_descricao(): void
    {
        $user = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($user);
        $estabelecimento = $this->criarEstabelecimento();

        $curso = Curso::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => 'INF',
            'nome' => 'Informática',
        ]);

        $this->assertSame($user->id, $curso->criado_por);
        $this->assertSame($user->id, $curso->editado_por);
        $this->assertSame(Estado::ATIVO->value, $curso->estado);
        $this->assertSame('Ativo', $curso->estado_descricao);
    }

    public function test_atualizar_estado_sincroniza_descricao_e_editado_por(): void
    {
        $criador = User::create(['name' => 'Criador', 'email' => 'criador@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($criador);
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $editor = User::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($editor);
        $curso->update(['estado' => Estado::INATIVO->value]);

        $curso->refresh();
        $this->assertSame(Estado::INATIVO->value, $curso->estado);
        $this->assertSame('Inativo', $curso->estado_descricao);
        $this->assertSame($editor->id, $curso->editado_por);
        $this->assertSame($criador->id, $curso->criado_por);
    }

    public function test_codigo_duplicado_no_mesmo_estabelecimento_viola_constraint(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->expectException(QueryException::class);
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Outro Nome']);
    }

    public function test_nome_duplicado_no_mesmo_estabelecimento_viola_constraint(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->expectException(QueryException::class);
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'OUTRO', 'nome' => 'Informática']);
    }

    public function test_curso_lista_os_seus_planos_curriculares(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $outroCurso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CONT', 'nome' => 'Contabilidade']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PLI', 'nome' => 'Plano Informática']);
        PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $outroCurso->id, 'codigo' => 'PLC', 'nome' => 'Plano Contabilidade']);

        $this->assertCount(1, $curso->planosCurriculares);
        $this->assertSame($plano->id, $curso->planosCurriculares->first()->id);
    }

    public function test_mesmo_codigo_e_nome_permitido_em_estabelecimentos_diferentes(): void
    {
        $estabelecimentoA = $this->criarEstabelecimento();
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);

        $cursoA = Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $cursoB = Curso::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->assertNotSame($cursoA->id, $cursoB->id);
    }
}
