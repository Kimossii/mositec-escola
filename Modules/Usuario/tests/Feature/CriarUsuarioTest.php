<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarUsuarioTest extends TestCase
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

    public function test_utilizador_sem_perfil_admin_escola_nao_acede_a_gestao_de_utilizadores(): void
    {
        $semPermissao = User::create(['name' => 'Professor', 'email' => 'professor.sem.perm@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($semPermissao);

        $this->get('/usuarios')->assertForbidden();
        $this->post('/usuarios/cadastrarUsuario', ['name' => 'X'])->assertForbidden();
    }

    public function test_cria_utilizador_com_email(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Professor Novo',
            'perfil' => 'professor',
            'tipo_login' => 'email',
            'email' => 'professor@example.com',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
            'estado' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'professor@example.com',
            'numero_matricula' => null,
        ]);
    }

    public function test_cria_aluno_a_partir_do_registo_do_aluno(): void
    {
        $this->actingAsStaff();
        $registo = $this->registarAluno('2026-0005', 'Aluno Novo');

        $response = $this->post('/usuarios/alunos/cadastrar', [
            'perfil' => 'aluno',
            'numero_matricula' => '2026-0005',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
            'estado' => 1,
        ]);

        $response->assertRedirect();
        $aluno = User::where('name', 'Aluno Novo')->first();

        $this->assertNotNull($aluno);
        $this->assertNull($aluno->email);
        $this->assertSame('2026-0005', $aluno->numero_matricula);
        $this->assertSame($registo->dados_pessoa_id, $aluno->dados_pessoa_id);
        $this->assertTrue($aluno->roles->contains('nome', Perfil::ALUNO->value));
    }

    private function registarAluno(string $matricula, string $nome): Aluno
    {
        $pessoa = DadosPessoa::create(['nome_completo' => $nome, 'numero_identificacao' => 'BI' . $matricula, 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        return Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => $matricula,
        ]);
    }

    public function test_email_e_obrigatorio_quando_tipo_login_e_email(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Sem Email',
            'perfil' => 'professor',
            'tipo_login' => 'email',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
        ]);

        $response->assertSessionHasErrors('email');
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
            'perfil' => 'encarregado',
            'tipo_login' => 'email',
            'email' => 'encarregado@example.com',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
            'matriculas_educandos' => ['2026-0001'],
        ]);

        $response->assertRedirect();

        $encarregado = User::where('email', 'encarregado@example.com')->first();
        $this->assertTrue($encarregado->educandos->contains($aluno));
        $this->assertTrue($encarregado->roles->contains('nome', Perfil::ENCARREGADO->value));
    }

    public function test_aluno_criado_pelo_endpoint_consegue_entrar_com_a_matricula_oficial(): void
    {
        $this->actingAsStaff();
        $this->registarAluno('2026-0006', 'Aluno Fluxo Completo');

        $this->post('/usuarios/alunos/cadastrar', [
            'perfil' => 'aluno',
            'numero_matricula' => '2026-0006',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
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
            'perfil' => 'encarregado',
            'tipo_login' => 'email',
            'email' => 'encarregado.fluxo@example.com',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
            'matriculas_educandos' => [$aluno->numero_matricula],
        ]);

        $encarregado = User::where('email', 'encarregado.fluxo@example.com')->first();
        $this->assertNotNull($encarregado);
        $this->assertTrue($encarregado->educandos->contains($aluno));
    }

    public function test_cria_utilizador_com_permissao_extra_alem_do_perfil(): void
    {
        $this->actingAsStaff();

        $modulo = \Modules\Permissao\Models\Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = \Modules\Permissao\Models\Acao::create(['nome' => 'exportar', 'numero' => 5, 'estado' => 1]);

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Professor Com Extra',
            'perfil' => 'professor',
            'tipo_login' => 'email',
            'email' => 'professor.extra@example.com',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
            'celulas' => [
                ['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true],
            ],
        ]);

        $response->assertRedirect();
        $user = User::where('email', 'professor.extra@example.com')->first();
        $this->assertDatabaseHas('user_permissoes', [
            'users_id' => $user->id,
            'modulo_id' => $modulo->id,
            'acao_id' => $acao->id,
            'permitido' => true,
        ]);
    }

    public function test_funcionario_nao_consegue_esconder_uma_concessao_de_autorizacao_no_cadastro(): void
    {
        $funcionario = User::create(['name' => 'Funcionário', 'email' => 'funcionario@example.com', 'password' => Hash::make('x')]);
        $funcionario->roles()->attach(Role::where('nome', Perfil::FUNCIONARIO->value)->first()->id);
        $this->actingAs($funcionario);

        $moduloAutorizacao = \Modules\Permissao\Models\Modulo::where('nome', 1)->first();
        $acaoEditar = \Modules\Permissao\Models\Acao::where('nome', 'editar')->first();

        // O Funcionário tem usuario.criar (pode cadastrar um Professor), mas
        // NUNCA devia conseguir, através do mesmo pedido, conceder-lhe
        // autorizacao.editar via celulas — isso é autorizacao.editar, que
        // o Funcionário não tem.
        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Professor Escalado',
            'perfil' => 'professor',
            'tipo_login' => 'email',
            'email' => 'professor.escalado@example.com',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
            'celulas' => [
                ['modulo_id' => $moduloAutorizacao->id, 'acao_id' => $acaoEditar->id, 'permitido' => true],
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'professor.escalado@example.com']);
    }
}
