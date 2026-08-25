<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): User
    {
        $staff = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Staff', 'password' => Hash::make('segredo123')],
        );

        $this->actingAs($staff);

        return $staff;
    }

    public function test_cria_utilizador_com_email(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Professor Novo',
            'tipo_login' => 'email',
            'email' => 'professor@example.com',
            'password' => 'segredo123',
            'estado' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'professor@example.com',
            'numero_matricula' => null,
        ]);
    }

    public function test_cria_aluno_com_matricula_gerada(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/usuarios/alunos/cadastrar', [
            'name' => 'Aluno Novo',
            'tipo_login' => 'matricula',
            'password' => 'segredo123',
            'estado' => 1,
        ]);

        $response->assertRedirect();
        $aluno = User::where('name', 'Aluno Novo')->first();

        $this->assertNotNull($aluno);
        $this->assertNull($aluno->email);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $aluno->numero_matricula);
    }

    public function test_email_e_obrigatorio_quando_tipo_login_e_email(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Sem Email',
            'tipo_login' => 'email',
            'password' => 'segredo123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_cria_encarregado_e_liga_aos_educandos_por_matricula(): void
    {
        $this->actingAsStaff();

        $aluno = User::create([
            'name' => 'Filho',
            'numero_matricula' => '2026-0001',
            'tipo_login' => TipoLogin::MATRICULA,
            'password' => Hash::make('segredo123'),
        ]);

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Encarregado',
            'tipo_login' => 'email',
            'email' => 'encarregado@example.com',
            'password' => 'segredo123',
            'matriculas_educandos' => ['2026-0001'],
        ]);

        $response->assertRedirect();

        $encarregado = User::where('email', 'encarregado@example.com')->first();
        $this->assertTrue($encarregado->educandos->contains($aluno));
    }

    public function test_aluno_criado_pelo_endpoint_consegue_entrar_com_a_matricula_gerada(): void
    {
        $this->actingAsStaff();

        $this->post('/usuarios/alunos/cadastrar', [
            'name' => 'Aluno Fluxo Completo',
            'tipo_login' => 'matricula',
            'password' => 'segredo123',
            'estado' => 1,
        ]);

        $aluno = User::where('name', 'Aluno Fluxo Completo')->first();
        $this->assertNotNull($aluno);

        Auth::logout();

        $loginResponse = $this->post('/login', [
            'login' => $aluno->numero_matricula,
            'password' => 'segredo123',
        ]);
        $loginResponse->assertRedirect('/');
        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::user()->is($aluno));

        Auth::logout();
        $this->actingAsStaff();

        $this->post('/usuarios/encarregados/cadastrar', [
            'name' => 'Encarregado Fluxo Completo',
            'tipo_login' => 'email',
            'email' => 'encarregado.fluxo@example.com',
            'password' => 'segredo123',
            'matriculas_educandos' => [$aluno->numero_matricula],
        ]);

        $encarregado = User::where('email', 'encarregado.fluxo@example.com')->first();
        $this->assertNotNull($encarregado);
        $this->assertTrue($encarregado->educandos->contains($aluno));
    }
}
