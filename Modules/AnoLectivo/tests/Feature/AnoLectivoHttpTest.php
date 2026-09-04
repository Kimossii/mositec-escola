<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
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
