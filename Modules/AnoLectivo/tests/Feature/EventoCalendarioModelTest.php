<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EventoCalendarioModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
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

    public function test_evento_pertence_ao_ano_lectivo_e_sincroniza_descricoes(): void
    {
        $this->actingAsStaff();

        $anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $evento = $anoLectivo->eventosCalendario()->create([
            'titulo' => 'Início das aulas',
            'tipo' => TipoEventoCalendario::AULA->value,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-09-01',
            'dia_inteiro' => true,
        ]);

        $this->assertTrue($evento->anoLectivo->is($anoLectivo));
        $this->assertSame('Aula', $evento->fresh()->tipo_descricao);
        $this->assertSame('Ativo', $evento->fresh()->estado_descricao);
    }
}
