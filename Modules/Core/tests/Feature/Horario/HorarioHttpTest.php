<?php

namespace Modules\Core\Tests\Feature\Horario;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Horario;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class HorarioHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-horario@example.com', 'password' => 'x']);
        $admin->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_admin_escola_cria_horario(): void
    {
        $this->actingAsAdmin();

        $this->post('/horarios', [
            'nome' => 'Manhã',
            'hora_inicio' => '08:00',
            'hora_fim' => '12:00',
        ])->assertRedirect();

        $this->assertDatabaseHas('horarios', ['nome' => 'Manhã']);
    }

    public function test_hora_fim_deve_ser_posterior_a_hora_inicio(): void
    {
        $this->actingAsAdmin();

        $this->post('/horarios', [
            'nome' => 'Manhã',
            'hora_inicio' => '12:00',
            'hora_fim' => '08:00',
        ])->assertSessionHasErrors('hora_fim');
    }

    public function test_admin_escola_actualiza_e_elimina_horario(): void
    {
        $this->actingAsAdmin();

        $horario = Horario::create(['nome' => 'Manhã', 'hora_inicio' => '08:00', 'hora_fim' => '12:00']);

        $this->put("/horarios/{$horario->id}", [
            'nome' => 'Manhã (revisto)',
            'hora_inicio' => '08:00',
            'hora_fim' => '12:30',
        ])->assertRedirect();
        $this->assertDatabaseHas('horarios', ['id' => $horario->id, 'nome' => 'Manhã (revisto)']);

        $this->delete("/horarios/{$horario->id}")->assertRedirect();
        $this->assertDatabaseMissing('horarios', ['id' => $horario->id]);
    }

    public function test_utilizador_sem_permissao_recebe_403_em_todas_as_rotas(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor-horario@example.com', 'password' => 'x']);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);
        $this->actingAs($professor);

        $horario = Horario::create(['nome' => 'Manhã', 'hora_inicio' => '08:00', 'hora_fim' => '12:00']);

        $this->get('/horarios')->assertForbidden();
        $this->post('/horarios', ['nome' => 'X', 'hora_inicio' => '08:00', 'hora_fim' => '09:00'])->assertForbidden();
        $this->put("/horarios/{$horario->id}", ['nome' => 'X', 'hora_inicio' => '08:00', 'hora_fim' => '09:00'])->assertForbidden();
        $this->delete("/horarios/{$horario->id}")->assertForbidden();
    }
}
