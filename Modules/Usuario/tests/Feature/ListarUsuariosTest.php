<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
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

        $this->seed(PermissaoDatabaseSeeder::class);
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
            ->has('usuarios.data', 1)
            ->where('usuarios.data.0.name', $aluno->name)
            ->where('usuarios.data.0.matricula', '2026-0001')
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
            ->has('usuarios.data', 1)
            ->where('usuarios.data.0.name', $professor->name)
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
            ->has('usuarios.data', 2)
        );
    }

    private function criarUtilizadores(int $quantidade, Perfil $perfil = Perfil::ALUNO): void
    {
        for ($i = 1; $i <= $quantidade; $i++) {
            $this->comPerfil(User::create([
                'name' => sprintf('Utilizador %02d', $i),
                'numero_matricula' => sprintf('2026-%04d', $i),
                'password' => Hash::make('segredo123'),
            ]), $perfil);
        }
    }

    public function test_listagem_e_paginada_de_10_em_10(): void
    {
        $this->actingAsStaff();
        $this->criarUtilizadores(12);

        $this->get('/usuarios/alunos')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 10)
            ->where('usuarios.total', 12)
            ->where('usuarios.last_page', 2)
        );

        $this->get('/usuarios/alunos?page=2')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 2)
            ->where('usuarios.current_page', 2)
        );
    }

    public function test_index_tambem_e_paginada(): void
    {
        $this->actingAsStaff();
        $this->criarUtilizadores(12);

        $this->get('/usuarios')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 10)
            ->where('usuarios.total', 13)
        );
    }

    public function test_ordena_por_nome_de_forma_estavel(): void
    {
        $this->actingAsStaff();
        $this->criarUtilizadores(3);

        $this->get('/usuarios/alunos')->assertInertia(fn (Assert $page) => $page
            ->where('usuarios.data.0.name', 'Utilizador 01')
            ->where('usuarios.data.2.name', 'Utilizador 03')
        );
    }

    public function test_pesquisa_por_nome(): void
    {
        $this->actingAsStaff();
        $this->criarUtilizadores(12);

        $this->get('/usuarios/alunos?pesquisa=Utilizador 07')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 1)
            ->where('usuarios.data.0.name', 'Utilizador 07')
        );
    }

    public function test_pesquisa_por_email(): void
    {
        $this->actingAsStaff();
        $this->comPerfil(User::create([
            'name' => 'Rui Costa',
            'email' => 'rui.costa@escola.ao',
            'password' => Hash::make('segredo123'),
        ]), Perfil::PROFESSOR);
        $this->criarUtilizadores(3, Perfil::PROFESSOR);

        $this->get('/usuarios/professores?pesquisa=rui.costa@')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 1)
            ->where('usuarios.data.0.email', 'rui.costa@escola.ao')
        );
    }

    public function test_pesquisa_por_matricula(): void
    {
        $this->actingAsStaff();
        $this->criarUtilizadores(12);

        $this->get('/usuarios/alunos?pesquisa=2026-0011')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 1)
            ->where('usuarios.data.0.matricula', '2026-0011')
        );
    }

    public function test_pesquisa_nao_sai_do_perfil_da_lista(): void
    {
        $this->actingAsStaff();
        $this->criarUtilizadores(2, Perfil::ALUNO);
        $this->comPerfil(User::create([
            'name' => 'Utilizador Prof',
            'email' => 'prof@example.com',
            'password' => Hash::make('segredo123'),
        ]), Perfil::PROFESSOR);

        $this->get('/usuarios/alunos?pesquisa=Utilizador')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 2)
        );
    }

    public function test_filtra_por_estado(): void
    {
        $this->actingAsStaff();
        $this->criarUtilizadores(3);
        User::where('name', 'Utilizador 02')->update(['estado' => 0]);

        $this->get('/usuarios/alunos?estado=0')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 1)
            ->where('usuarios.data.0.name', 'Utilizador 02')
        );

        $this->get('/usuarios/alunos?estado=1')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 2)
        );

        // "Todos" chega como string vazia e não pode filtrar nada.
        $this->get('/usuarios/alunos?estado=')->assertInertia(fn (Assert $page) => $page
            ->has('usuarios.data', 3)
        );
    }

    public function test_links_de_paginacao_preservam_a_pesquisa(): void
    {
        $this->actingAsStaff();
        $this->criarUtilizadores(12);

        $this->get('/usuarios/alunos?pesquisa=Utilizador')->assertInertia(fn (Assert $page) => $page
            ->where('usuarios.links.2.url', fn ($url) => str_contains($url, 'pesquisa=Utilizador') && str_contains($url, 'page=2'))
        );
    }

    public function test_devolve_os_filtros_aplicados(): void
    {
        $this->actingAsStaff();

        $this->get('/usuarios/alunos?pesquisa=abc&estado=1')->assertInertia(fn (Assert $page) => $page
            ->where('filtros.pesquisa', 'abc')
            ->where('filtros.estado', '1')
        );
    }
}
