<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EliminarUsuarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_elimina_utilizador_sem_vinculos(): void
    {
        $this->actingAsStaff();

        $professor = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x'), 'tipo_login' => TipoLogin::EMAIL]);

        $response = $this->delete("/usuarios/{$professor->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $professor->id]);
    }

    public function test_nao_elimina_encarregado_com_educandos_vinculados(): void
    {
        $this->actingAsStaff();

        $aluno = User::create(['name' => 'Filho', 'numero_matricula' => '2026-0001', 'tipo_login' => TipoLogin::MATRICULA, 'password' => Hash::make('x')]);
        $encarregado = User::create(['name' => 'Pai', 'email' => 'pai@example.com', 'password' => Hash::make('x'), 'tipo_login' => TipoLogin::EMAIL]);
        $encarregado->educandos()->attach($aluno->id, ['parentesco' => 'pai']);

        $response = $this->delete("/usuarios/{$encarregado->id}");

        $response->assertSessionHasErrors('utilizador');
        $this->assertDatabaseHas('users', ['id' => $encarregado->id]);
    }

    public function test_nao_elimina_aluno_com_encarregados_vinculados(): void
    {
        $this->actingAsStaff();

        $aluno = User::create(['name' => 'Filho', 'numero_matricula' => '2026-0002', 'tipo_login' => TipoLogin::MATRICULA, 'password' => Hash::make('x')]);
        $encarregado = User::create(['name' => 'Mãe', 'email' => 'mae@example.com', 'password' => Hash::make('x'), 'tipo_login' => TipoLogin::EMAIL]);
        $encarregado->educandos()->attach($aluno->id, ['parentesco' => 'mae']);

        $response = $this->delete("/usuarios/{$aluno->id}");

        $response->assertSessionHasErrors('utilizador');
        $this->assertDatabaseHas('users', ['id' => $aluno->id]);
    }

    public function test_alterna_estado_do_utilizador(): void
    {
        $this->actingAsStaff();

        $professor = User::create(['name' => 'Prof', 'email' => 'prof.estado@example.com', 'password' => Hash::make('x'), 'estado' => 1]);

        $response = $this->patch("/usuarios/{$professor->id}/estado");

        $response->assertRedirect();
        $this->assertSame(0, $professor->fresh()->estado);

        $this->patch("/usuarios/{$professor->id}/estado");

        $this->assertSame(1, $professor->fresh()->estado);
    }

    public function test_nao_elimina_a_propria_conta(): void
    {
        $staff = $this->actingAsStaff();

        $response = $this->delete("/usuarios/{$staff->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_nao_elimina_a_propria_conta_via_inertia_volta_com_mensagem_amigavel(): void
    {
        $staff = $this->actingAsStaff();

        $response = $this->withHeaders(['X-Inertia' => 'true'])->delete("/usuarios/{$staff->id}");

        $response->assertRedirect();
        $response->assertSessionHasErrors(['autorizacao' => 'Não pode eliminar a sua própria conta.']);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_utilizador_sem_permissao_nao_elimina(): void
    {
        $semPermissao = User::create(['name' => 'Sem Permissao', 'email' => 'sem.permissao@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($semPermissao);

        $alvo = User::create(['name' => 'Alvo', 'email' => 'alvo@example.com', 'password' => Hash::make('x')]);

        $response = $this->delete("/usuarios/{$alvo->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $alvo->id]);
    }
}
