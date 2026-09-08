<?php

namespace Modules\Turma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class TurmaHttpTest extends TestCase
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
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'is_active' => true]);
    }

    private function criarAnoLectivo(Estabelecimento $estabelecimento): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => '2026',
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
    }

    public function test_cria_nivel_academico_via_http_infere_estabelecimento_actual_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('niveis-academicos.store'), [
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $nivel = NivelAcademico::firstWhere('codigo', '1C');
        $this->assertNotNull($nivel);
        $this->assertSame($staff->id, $nivel->criado_por);
        $this->assertSame(Estabelecimento::current()->id, $nivel->estabelecimento_id);
        $this->assertSame(1, $nivel->estado);
        $this->assertSame('Ativo', $nivel->estado_descricao);
    }

    public function test_cria_turno_via_http(): void
    {
        $this->actingAsStaff();

        $this->post(route('turnos.store'), [
            'nome' => 'Manhã',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $turno = Turno::firstWhere('nome', 'Manhã');
        $this->assertNotNull($turno);
        $this->assertSame('Ativo', $turno->estado_descricao);
    }

    public function test_cria_turma_via_http_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1]);

        $this->post(route('turmas.store'), [
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $turma = Turma::firstWhere('codigo', 'T1');
        $this->assertNotNull($turma);
        $this->assertSame($staff->id, $turma->criado_por);
        $this->assertSame(1, $turma->estado);
        $this->assertSame('Ativo', $turma->estado_descricao);
    }

    public function test_altera_estado_da_turma_via_http_e_sincroniza_descricao(): void
    {
        $staff = $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->patch(route('turmas.alterar-estado', $turma), [
            'estado' => Estado::INATIVO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $turma->refresh();
        $this->assertSame(Estado::INATIVO->value, $turma->estado);
        $this->assertSame('Inativo', $turma->estado_descricao);
        $this->assertSame($staff->id, $turma->editado_por);
    }

    public function test_elimina_turma_via_http_com_soft_delete(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->delete(route('turmas.destroy', $turma))->assertRedirect();

        $this->assertSoftDeleted('turmas', ['id' => $turma->id]);
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();

        $this->post(route('turmas.store'), ['codigo' => 'T1', 'nome' => 'Turma 1'])->assertForbidden();
        $this->post(route('turnos.store'), ['nome' => 'Manhã'])->assertForbidden();
        $this->post(route('niveis-academicos.store'), ['codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1])->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('turmas.index'))->assertForbidden();
        $this->get(route('turnos.index'))->assertForbidden();
        $this->get(route('niveis-academicos.index'))->assertForbidden();
    }
}
