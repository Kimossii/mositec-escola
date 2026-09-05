<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EventoCalendarioHttpTest extends TestCase
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

    public function test_cria_evento_de_calendario_via_http(): void
    {
        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/eventos-calendario", [
            'titulo' => 'Início das aulas',
            'tipo' => TipoEventoCalendario::AULA->value,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-09-01',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('eventos_calendario', ['titulo' => 'Início das aulas']);
    }

    public function test_rejeita_evento_fora_do_intervalo_via_http(): void
    {
        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/eventos-calendario", [
            'titulo' => 'Fora do intervalo',
            'tipo' => TipoEventoCalendario::EVENTO->value,
            'data_inicio' => '2027-08-01',
            'data_fim' => '2027-08-15',
        ]);

        $response->assertSessionHasErrors('data_inicio');
    }

    public function test_omitir_dia_inteiro_na_atualizacao_retorna_422(): void
    {
        $this->post("/ano-lectivos/{$this->anoLectivo->id}/eventos-calendario", [
            'titulo' => 'Reunião de professores',
            'tipo' => TipoEventoCalendario::REUNIAO->value,
            'data_inicio' => '2026-09-10',
            'data_fim' => '2026-09-10',
            'dia_inteiro' => false,
        ]);
        $evento = EventoCalendario::where('titulo', 'Reunião de professores')->firstOrFail();
        $this->assertFalse($evento->dia_inteiro);

        $response = $this->put("/eventos-calendario/{$evento->id}", [
            'titulo' => 'Reunião de professores (actualizada)',
            'tipo' => TipoEventoCalendario::REUNIAO->value,
            'data_inicio' => '2026-09-10',
            'data_fim' => '2026-09-10',
            // 'dia_inteiro' omitido de propósito
        ]);

        $response->assertSessionHasErrors('dia_inteiro');
        $evento->refresh();
        $this->assertFalse((bool) $evento->dia_inteiro);
        $this->assertSame('Reunião de professores', $evento->titulo);
    }
}
