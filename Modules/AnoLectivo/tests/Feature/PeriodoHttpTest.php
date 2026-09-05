<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PeriodoHttpTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Staff', 'password' => Hash::make('segredo123')],
        );
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $this->anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
    }

    public function test_cria_periodo_via_http(): void
    {
        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('periodos', ['nome' => '1.º Trimestre']);
    }

    public function test_rejeita_periodo_fora_do_intervalo_via_http(): void
    {
        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => 'Fora do intervalo',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-08-01',
            'data_fim' => '2026-08-15',
        ]);

        $response->assertSessionHasErrors('data_inicio');
    }

    public function test_rejeita_periodos_sobrepostos_via_http(): void
    {
        $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => '2.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 2,
            'data_inicio' => '2026-12-01',
            'data_fim' => '2027-03-31',
        ]);

        $response->assertSessionHasErrors('data_inicio');
    }

    public function test_criar_periodo_com_numero_duplicado_retorna_422(): void
    {
        $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => '2.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-12-16',
            'data_fim' => '2027-03-31',
        ]);

        $response->assertSessionHasErrors('numero');
        $this->assertSame(1, Periodo::where('ano_lectivo_id', $this->anoLectivo->id)->count());
    }

    public function test_atualizar_periodo_sem_alterar_numero_tem_sucesso(): void
    {
        $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);
        $periodo = Periodo::where('nome', '1.º Trimestre')->firstOrFail();

        $response = $this->put("/periodos/{$periodo->id}", [
            'nome' => '1.º Trimestre (ajustado)',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-05',
            'data_fim' => '2026-12-20',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('periodos', ['id' => $periodo->id, 'nome' => '1.º Trimestre (ajustado)']);
    }

    public function test_permite_multiplos_periodos_sem_numero_no_mesmo_ano_lectivo(): void
    {
        $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => 'Período A',
            'tipo' => TipoPeriodo::OUTRO->value,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => 'Período B',
            'tipo' => TipoPeriodo::OUTRO->value,
            'data_inicio' => '2026-12-16',
            'data_fim' => '2027-03-31',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('periodos', ['nome' => 'Período A', 'numero' => null]);
        $this->assertDatabaseHas('periodos', ['nome' => 'Período B', 'numero' => null]);
    }
}
