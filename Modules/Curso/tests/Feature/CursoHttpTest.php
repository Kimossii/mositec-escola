<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CursoHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Staff', 'password' => Hash::make('segredo123')],
        );
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        $this->actingAs($staff);

        return $staff;
    }

    private function actingAsProfessor(): User
    {
        $professor = User::firstOrCreate(
            ['email' => 'professor@example.com'],
            ['name' => 'Professor', 'password' => Hash::make('segredo123')],
        );
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->actingAs($professor);

        return $professor;
    }

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    public function test_cria_curso_via_http_infere_estabelecimento_actual_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('cursos.store'), [
            'codigo' => 'INF',
            'nome' => 'Informática',
            'descricao' => 'Curso técnico de Informática',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $curso = Curso::firstWhere('codigo', 'INF');
        $this->assertNotNull($curso);
        $this->assertSame($staff->id, $curso->criado_por);
        $this->assertSame(Estabelecimento::current()->id, $curso->estabelecimento_id);
        $this->assertSame(Estado::ATIVO->value, $curso->estado);
        $this->assertSame('Ativo', $curso->estado_descricao);
    }

    public function test_atualiza_curso_via_http_e_actualiza_editado_por(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $outroStaff = User::create(['name' => 'Outro Staff', 'email' => 'outro@example.com', 'password' => Hash::make('x')]);
        $outroStaff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($outroStaff);

        $this->put(route('cursos.update', $curso), [
            'codigo' => 'INF',
            'nome' => 'Informática e Sistemas',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $curso->refresh();
        $this->assertSame('Informática e Sistemas', $curso->nome);
        $this->assertSame($outroStaff->id, $curso->editado_por);
    }

    public function test_altera_estado_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->patch(route('cursos.alterar-estado', $curso), [
            'estado' => Estado::INATIVO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(Estado::INATIVO->value, $curso->fresh()->estado);
    }

    public function test_atualizar_curso_mantendo_o_proprio_codigo_e_nome_nao_falha(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->put(route('cursos.update', $curso), [
            'codigo' => 'INF',
            'nome' => 'Informática',
            'descricao' => 'Descrição actualizada',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Descrição actualizada', $curso->fresh()->descricao);
    }

    public function test_atualizar_curso_com_codigo_de_outro_curso_do_mesmo_estabelecimento_falha(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $cursoEmEdicao = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CONT', 'nome' => 'Contabilidade']);

        $this->put(route('cursos.update', $cursoEmEdicao), [
            'codigo' => 'INF',
            'nome' => 'Contabilidade',
        ])->assertSessionHasErrors('codigo');
    }

    public function test_index_expoe_apenas_cursos_do_estabelecimento_actual(): void
    {
        $this->actingAsStaff();
        $estabelecimentoActual = $this->criarEstabelecimento();
        Curso::create(['estabelecimento_id' => $estabelecimentoActual->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $outroEstabelecimento = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);
        Curso::create(['estabelecimento_id' => $outroEstabelecimento->id, 'codigo' => 'CONT', 'nome' => 'Contabilidade']);

        $this->get(route('cursos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Curso/Index')
            ->has('cursos', 1)
            ->where('cursos.0.codigo', 'INF')
        );
    }

    public function test_show_expoe_o_curso(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->get(route('cursos.show', $curso))->assertInertia(fn (Assert $page) => $page
            ->component('Curso/Show')
            ->where('curso.codigo', 'INF')
        );
    }

    public function test_show_expoe_os_planos_curriculares_do_curso(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PLI', 'nome' => 'Plano Informática']);

        $this->get(route('cursos.show', $curso))->assertInertia(fn (Assert $page) => $page
            ->component('Curso/Show')
            ->has('curso.planos_curriculares', 1)
            ->where('curso.planos_curriculares.0.codigo', 'PLI')
        );
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->post(route('cursos.store'), ['codigo' => 'INF2', 'nome' => 'Outro Curso'])->assertForbidden();
        $this->put(route('cursos.update', $curso), ['codigo' => 'INF', 'nome' => 'Informática Renovada'])->assertForbidden();
        $this->patch(route('cursos.alterar-estado', $curso), ['estado' => 0])->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('cursos.index'))->assertForbidden();
    }
}
