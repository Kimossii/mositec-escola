<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SalaHttpTest extends TestCase
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

    public function test_cria_sala_via_http_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();

        $this->post(route('salas.store'), [
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
            'capacidade' => 30,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $sala = Sala::firstWhere('codigo', 'A101');
        $this->assertNotNull($sala);
        $this->assertSame($staff->id, $sala->criado_por);
        $this->assertSame($staff->id, $sala->editado_por);
    }

    public function test_atualiza_sala_via_http_e_actualiza_editado_por(): void
    {
        $this->actingAsStaff();
        $sala = Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value]);

        $outroStaff = User::create(['name' => 'Outro Staff', 'email' => 'outro@example.com', 'password' => Hash::make('x')]);
        $outroStaff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($outroStaff);

        $this->put(route('salas.update', $sala), [
            'codigo' => 'A101',
            'nome' => 'Sala 101 Renovada',
            'tipo' => TipoSala::SALA_AULA->value,
            'estado' => EstadoSala::ATIVA->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $sala->refresh();
        $this->assertSame('Sala 101 Renovada', $sala->nome);
        $this->assertSame($outroStaff->id, $sala->editado_por);
    }

    public function test_altera_estado_via_http(): void
    {
        $this->actingAsStaff();
        $sala = Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value]);

        $this->patch(route('salas.alterar-estado', $sala), [
            'estado' => EstadoSala::MANUTENCAO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(EstadoSala::MANUTENCAO, $sala->fresh()->estado);
    }

    public function test_elimina_sala_via_http_com_soft_delete(): void
    {
        $this->actingAsStaff();
        $sala = Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value]);

        $this->delete(route('salas.destroy', $sala))->assertRedirect();

        $this->assertSoftDeleted('salas', ['id' => $sala->id]);
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();

        $this->post(route('salas.store'), ['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value])
            ->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('salas.index'))->assertForbidden();
    }
}
