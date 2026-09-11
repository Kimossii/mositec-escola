<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PlanoCurricularModelTest extends TestCase
{
    use RefreshDatabase;

    private function estabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
    }

    public function test_cria_plano_pertencente_a_curso_e_estabelecimento(): void
    {
        $estabelecimento = $this->estabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);

        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC-2026',
            'nome' => 'Plano 2026',
        ]);

        $this->assertSame($estabelecimento->id, $plano->estabelecimento->id);
        $this->assertSame($curso->id, $plano->curso->id);
        $this->assertSame('Ativo', $plano->fresh()->estado_descricao);
    }

    public function test_autoria_preenchida_automaticamente(): void
    {
        $user = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($user);
        $estabelecimento = $this->estabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);

        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC-2026',
            'nome' => 'Plano 2026',
        ]);

        $this->assertSame($user->id, $plano->criado_por);
        $this->assertSame($user->id, $plano->editado_por);
    }

    public function test_codigo_unico_por_estabelecimento(): void
    {
        $estabelecimento = $this->estabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC-2026', 'nome' => 'Plano 2026']);

        $this->expectException(QueryException::class);
        PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC-2026', 'nome' => 'Plano Duplicado']);
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        $estabelecimentoA = $this->estabelecimento();
        Estabelecimento::where('id', $estabelecimentoA->id)->update(['is_active' => false]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $cursoA = Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $cursoB = Curso::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'C1', 'nome' => 'Curso B']);

        PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoA->id, 'curso_id' => $cursoA->id, 'codigo' => 'PC-2026', 'nome' => 'Plano A']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoB->id, 'curso_id' => $cursoB->id, 'codigo' => 'PC-2026', 'nome' => 'Plano B']);

        $this->assertNotNull($plano->id);
    }

    public function test_nao_possui_colunas_ano_lectivo_id_nem_modalidade(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('planos_curriculares', 'ano_lectivo_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('planos_curriculares', 'modalidade'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('planos_curriculares', 'tipo_ensino'));
    }
}
