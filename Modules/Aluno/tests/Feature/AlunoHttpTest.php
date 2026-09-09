<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlunoHttpTest extends TestCase
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

    private function criarEstabelecimento(bool $activo = true): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => $activo]);
    }

    public function test_cria_aluno_via_http_infere_estabelecimento_actual_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('alunos.store'), [
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI0001',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $aluno = Aluno::firstWhere('dados_pessoa_id', DadosPessoal::firstWhere('numero_identificacao', 'BI0001')?->id);
        $this->assertNotNull($aluno);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $aluno->numero_matricula);
        $this->assertSame($staff->id, $aluno->criado_por);
        $this->assertSame(Estabelecimento::current()->id, $aluno->estabelecimento_id);
        $this->assertSame('Ana Silva', $aluno->dadosPessoa->nome_completo);
    }

    public function test_actualiza_aluno_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->put(route('alunos.update', $aluno), [
            'nome_completo' => 'Ana Silva Santos',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Ana Silva Santos', $aluno->dadosPessoa->fresh()->nome_completo);
        $this->assertSame('2026-0001', $aluno->fresh()->numero_matricula);
    }

    public function test_altera_estado_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->patch(route('alunos.alterar-estado', $aluno), ['estado' => Estado::INATIVO->value])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(Estado::INATIVO->value, $aluno->fresh()->estado);
    }

    public function test_index_expoe_apenas_alunos_do_estabelecimento_actual(): void
    {
        $this->actingAsStaff();
        $actual = $this->criarEstabelecimento();
        $pessoa1 = DadosPessoal::create(['nome_completo' => 'Ana', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $actual->id, 'dados_pessoa_id' => $pessoa1->id, 'numero_matricula' => '2026-0001']);

        $outra = $this->criarEstabelecimento(false);
        $pessoa2 = DadosPessoal::create(['nome_completo' => 'Bruno', 'numero_identificacao' => 'BI0002', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $outra->id, 'dados_pessoa_id' => $pessoa2->id, 'numero_matricula' => '2026-0002']);

        $this->get(route('alunos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Index')
            ->has('alunos', 1)
            ->where('alunos.0.numero_matricula', '2026-0001')
        );
    }

    public function test_show_expoe_o_aluno(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->get(route('alunos.show', $aluno))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Show')
            ->where('aluno.numero_matricula', '2026-0001')
        );
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->post(route('alunos.store'), ['nome_completo' => 'X', 'numero_identificacao' => 'BI9999'])->assertForbidden();
        $this->put(route('alunos.update', $aluno), ['nome_completo' => 'Y'])->assertForbidden();
        $this->patch(route('alunos.alterar-estado', $aluno), ['estado' => 0])->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('alunos.index'))->assertForbidden();
    }
}
