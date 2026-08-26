<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ListarUsuariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('segredo123'),
        ]);
        $staff->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);

        $this->actingAs($staff);

        return $staff;
    }

    private function comPerfil(User $user, Perfil $perfil): User
    {
        $user->roles()->attach(Role::where('nome', $perfil->value)->first()->id);

        return $user;
    }

    public function test_lista_de_alunos_mostra_so_utilizadores_com_perfil_aluno(): void
    {
        $this->actingAsStaff();

        $aluno = $this->comPerfil(User::create([
            'name' => 'Aluno Real',
            'numero_matricula' => '2026-0001',
            'password' => Hash::make('segredo123'),
        ]), Perfil::ALUNO);

        $this->comPerfil(User::create([
            'name' => 'Professor Real',
            'email' => 'professor.real@example.com',
            'password' => Hash::make('segredo123'),
        ]), Perfil::PROFESSOR);

        $response = $this->get('/usuarios/alunos');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Usuario/Alunos')
            ->has('usuarios', 1)
            ->where('usuarios.0.name', $aluno->name)
            ->where('usuarios.0.matricula', '2026-0001')
        );
    }

    public function test_lista_de_professores_nao_mostra_alunos(): void
    {
        $this->actingAsStaff();

        $this->comPerfil(User::create([
            'name' => 'Aluno Real',
            'numero_matricula' => '2026-0002',
            'password' => Hash::make('segredo123'),
        ]), Perfil::ALUNO);

        $professor = $this->comPerfil(User::create([
            'name' => 'Professor Real',
            'email' => 'professor.real2@example.com',
            'password' => Hash::make('segredo123'),
        ]), Perfil::PROFESSOR);

        $response = $this->get('/usuarios/professores');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Usuario/Professores')
            ->has('usuarios', 1)
            ->where('usuarios.0.name', $professor->name)
        );
    }

    public function test_index_lista_todos_os_utilizadores(): void
    {
        $staff = $this->actingAsStaff();

        $this->comPerfil(User::create([
            'name' => 'Aluno Real',
            'numero_matricula' => '2026-0003',
            'password' => Hash::make('segredo123'),
        ]), Perfil::ALUNO);

        $response = $this->get('/usuarios');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Usuario/Index')
            ->has('usuarios', 2)
        );
    }
}
