<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AnoLectivoModelTest extends TestCase
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

    public function test_criar_ano_lectivo_preenche_estado_descricao_e_auditoria(): void
    {
        $staff = $this->actingAsStaff();

        $anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        $this->assertSame('Planeado', $anoLectivo->fresh()->estado_descricao);
        $this->assertSame($staff->id, $anoLectivo->criado_por);
        $this->assertSame($staff->id, $anoLectivo->editado_por);
    }

    public function test_atualizar_ano_lectivo_actualiza_editado_por(): void
    {
        $this->actingAsStaff();

        $anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        $editor = User::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => Hash::make('x')]);
        $editor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($editor);

        $anoLectivo->update(['nome' => '2026/2027 (revisto)']);

        $this->assertSame($editor->id, $anoLectivo->fresh()->editado_por);
    }

    public function test_soft_delete_mantem_o_registo_na_base_de_dados(): void
    {
        $this->actingAsStaff();

        $anoLectivo = AnoLectivo::create([
            'nome' => '2024/2025',
            'data_inicio' => '2024-09-01',
            'data_fim' => '2025-07-31',
            'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);

        $anoLectivo->delete();

        $this->assertSoftDeleted('ano_lectivos', ['id' => $anoLectivo->id]);
    }

    public function test_current_devolve_o_ano_lectivo_activo(): void
    {
        $this->actingAsStaff();

        AnoLectivo::create([
            'nome' => '2025/2026',
            'data_inicio' => '2025-09-01',
            'data_fim' => '2026-07-31',
            'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);

        $ativo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $this->assertTrue(AnoLectivo::current()->is($ativo));
    }
}
