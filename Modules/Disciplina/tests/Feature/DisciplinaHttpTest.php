<?php

namespace Modules\Disciplina\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Enums\Estado;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DisciplinaHttpTest extends TestCase
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

    public function test_cria_disciplina_via_http_infere_estabelecimento_actual_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('disciplinas.store'), [
            'codigo' => 'INF',
            'nome' => 'Informática',
            'descricao' => 'Disciplina técnico de Informática',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $disciplina = Disciplina::firstWhere('codigo', 'INF');
        $this->assertNotNull($disciplina);
        $this->assertSame($staff->id, $disciplina->criado_por);
        $this->assertSame(Estabelecimento::current()->id, $disciplina->estabelecimento_id);
        $this->assertSame(Estado::ATIVO->value, $disciplina->estado);
        $this->assertSame('Ativo', $disciplina->estado_descricao);
    }

    public function test_atualiza_disciplina_via_http_e_actualiza_editado_por(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $outroStaff = User::create(['name' => 'Outro Staff', 'email' => 'outro@example.com', 'password' => Hash::make('x')]);
        $outroStaff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($outroStaff);

        $this->put(route('disciplinas.update', $disciplina), [
            'codigo' => 'INF',
            'nome' => 'Informática e Sistemas',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $disciplina->refresh();
        $this->assertSame('Informática e Sistemas', $disciplina->nome);
        $this->assertSame($outroStaff->id, $disciplina->editado_por);
    }

    public function test_altera_estado_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->patch(route('disciplinas.alterar-estado', $disciplina), [
            'estado' => Estado::INATIVO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(Estado::INATIVO->value, $disciplina->fresh()->estado);
    }

    public function test_atualizar_disciplina_mantendo_o_proprio_codigo_e_nome_nao_falha(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->put(route('disciplinas.update', $disciplina), [
            'codigo' => 'INF',
            'nome' => 'Informática',
            'descricao' => 'Descrição actualizada',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Descrição actualizada', $disciplina->fresh()->descricao);
    }

    public function test_atualizar_disciplina_com_codigo_de_outra_disciplina_do_mesmo_estabelecimento_falha(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $disciplinaEmEdicao = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CONT', 'nome' => 'Contabilidade']);

        $this->put(route('disciplinas.update', $disciplinaEmEdicao), [
            'codigo' => 'INF',
            'nome' => 'Contabilidade',
        ])->assertSessionHasErrors('codigo');
    }

    public function test_index_expoe_apenas_disciplinas_do_estabelecimento_actual(): void
    {
        $this->actingAsStaff();
        $estabelecimentoActual = $this->criarEstabelecimento();
        Disciplina::create(['estabelecimento_id' => $estabelecimentoActual->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $outroEstabelecimento = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);
        Disciplina::create(['estabelecimento_id' => $outroEstabelecimento->id, 'codigo' => 'CONT', 'nome' => 'Contabilidade']);

        $this->get(route('disciplinas.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Disciplina/Index')
            ->has('disciplinas', 1)
            ->where('disciplinas.0.codigo', 'INF')
        );
    }

    public function test_show_expoe_a_disciplina(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->get(route('disciplinas.show', $disciplina))->assertInertia(fn (Assert $page) => $page
            ->component('Disciplina/Show')
            ->where('disciplina.codigo', 'INF')
        );
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();
        $estabelecimento = $this->criarEstabelecimento();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->post(route('disciplinas.store'), ['codigo' => 'INF2', 'nome' => 'Outra Disciplina'])->assertForbidden();
        $this->put(route('disciplinas.update', $disciplina), ['codigo' => 'INF', 'nome' => 'Informática Renovada'])->assertForbidden();
        $this->patch(route('disciplinas.alterar-estado', $disciplina), ['estado' => 0])->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('disciplinas.index'))->assertForbidden();
    }
}
