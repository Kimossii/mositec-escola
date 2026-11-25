<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\TurnoHorario;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class TempoEmUsoNasChamadasTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    public function test_tempo_com_chamadas_registadas_nao_sai_do_turno_mesmo_sem_a_grelha(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $c = $this->cenarioDeFrequencia();
        $this->aulaComRegistos($c, '2026-03-02');
        $c['entrada']->delete(); // a grelha já não usa o tempo, mas há uma chamada feita nele
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $ligacao = TurnoHorario::where('turno_id', $c['turno']->id)->where('horario_id', $c['tempo1']->id)->first();

        $this->actingAs($admin)->delete(route('turnos.horarios.destroy', [$c['turno'], $ligacao]))
            ->assertSessionHasErrors(['horario']);

        $this->assertNotNull($ligacao->fresh());
    }
}
