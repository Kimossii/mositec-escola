<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AnoLectivoHttpTest extends TestCase
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

    public function test_cria_ano_lectivo_via_http(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ano_lectivos', ['nome' => '2026/2027']);
    }

    public function test_bloqueia_segundo_ano_lectivo_activo_via_http(): void
    {
        $this->actingAsStaff();

        $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $response = $this->post('/ano-lectivos', [
            'nome' => '2027/2028',
            'data_inicio' => '2027-09-01',
            'data_fim' => '2028-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $response->assertSessionHasErrors('estado');
        $this->assertSame(1, AnoLectivo::where('estado', EstadoAnoLectivo::ATIVO->value)->count());
    }

    public function test_encerrar_mantem_o_ano_lectivo_consultavel(): void
    {
        $this->actingAsStaff();

        $this->post('/ano-lectivos', [
            'nome' => '2025/2026',
            'data_inicio' => '2025-09-01',
            'data_fim' => '2026-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
        $anoLectivo = AnoLectivo::where('nome', '2025/2026')->firstOrFail();

        $this->patch("/ano-lectivos/{$anoLectivo->id}/estado", ['estado' => EstadoAnoLectivo::ENCERRADO->value])
            ->assertRedirect();

        $this->get("/ano-lectivos/{$anoLectivo->id}")->assertOk();
        $this->assertDatabaseHas('ano_lectivos', ['id' => $anoLectivo->id, 'estado' => EstadoAnoLectivo::ENCERRADO->value]);
    }

    public function test_encerrar_com_matriculas_pede_confirmacao_e_depois_resolve_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);

        $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
        $anoLectivo = AnoLectivo::where('nome', '2026/2027')->firstOrFail();

        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1,
        ]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001', 'data_matricula' => '2026-02-01', 'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        // Sem confirmação — recusado.
        $this->patch("/ano-lectivos/{$anoLectivo->id}/estado", ['estado' => EstadoAnoLectivo::ENCERRADO->value])
            ->assertSessionHasErrors('ano_lectivo');
        $this->assertSame(EstadoAnoLectivo::ATIVO->value, $anoLectivo->fresh()->estado->value);

        // Com confirmação — encerra e resolve a matrícula.
        $this->patch("/ano-lectivos/{$anoLectivo->id}/estado", [
            'estado' => EstadoAnoLectivo::ENCERRADO->value,
            'confirmar_encerramento_matriculas' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(EstadoAnoLectivo::ENCERRADO->value, $anoLectivo->fresh()->estado->value);
        $this->assertSame(EstadoMatriculaEnum::CONCLUIDA, $matricula->fresh()->estado);
    }

    public function test_bloqueia_eliminar_ano_lectivo_com_periodos_via_http(): void
    {
        $this->actingAsStaff();

        $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
        $anoLectivo = AnoLectivo::where('nome', '2026/2027')->firstOrFail();

        $this->post("/ano-lectivos/{$anoLectivo->id}/periodos", [
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        $response = $this->delete("/ano-lectivos/{$anoLectivo->id}");

        $response->assertSessionHasErrors('ano_lectivo');
        $this->assertDatabaseHas('ano_lectivos', ['id' => $anoLectivo->id, 'deleted_at' => null]);
    }

    public function test_criar_ano_lectivo_com_nome_duplicado_retorna_422(): void
    {
        $this->actingAsStaff();

        $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        $response = $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2027-09-01',
            'data_fim' => '2028-07-31',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        $response->assertSessionHasErrors('nome');
        $this->assertSame(1, AnoLectivo::where('nome', '2026/2027')->count());
    }

    public function test_atualizar_ano_lectivo_sem_alterar_nome_tem_sucesso(): void
    {
        $this->actingAsStaff();

        $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);
        $anoLectivo = AnoLectivo::where('nome', '2026/2027')->firstOrFail();

        $response = $this->put("/ano-lectivos/{$anoLectivo->id}", [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-15',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame('2026-09-15', $anoLectivo->fresh()->data_inicio->toDateString());
    }

    public function test_utilizador_sem_permissao_recebe_403_em_todas_as_rotas(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);
        $this->actingAs($professor);

        $this->get('/ano-lectivos')->assertForbidden();
        $this->post('/ano-lectivos', [])->assertForbidden();
    }
}
