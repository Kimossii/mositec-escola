<?php

namespace Modules\Horario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Horario\Tests\Support\CenarioDeHorario;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\TurnoHorario;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class TempoEmUsoNaGrelhaTest extends TestCase
{
    use CenarioDeHorario;
    use RefreshDatabase;

    public function test_tempo_que_esta_na_grelha_nao_sai_do_turno_mas_um_livre_sim(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);
        $usado = TurnoHorario::where('turno_id', $c['turno']->id)->where('horario_id', $c['tempo1']->id)->first();
        $livre = TurnoHorario::where('turno_id', $c['turno']->id)->where('horario_id', $c['tempo2']->id)->first();

        $this->delete(route('turnos.horarios.destroy', [$c['turno'], $usado]))->assertSessionHasErrors(['horario']);
        $this->delete(route('turnos.horarios.destroy', [$c['turno'], $livre]))->assertSessionHasNoErrors();

        $this->assertNotNull($usado->fresh());
        $this->assertNull($livre->fresh());
    }
}
