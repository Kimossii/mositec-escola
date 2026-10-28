<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ExportarUtilizadoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizador(string $nome, string $email, ?Perfil $perfil, int $estado = 1): User
    {
        $user = User::create(['name' => $nome, 'email' => $email, 'password' => Hash::make('x'), 'estado' => $estado]);
        if ($perfil !== null) {
            $user->roles()->attach(Role::where('nome', $perfil->value)->first()->id);
        }

        return $user;
    }

    private function csv(string $url, User $quem): string
    {
        $resposta = $this->actingAs($quem)->get($url)->assertOk();

        return file_get_contents($resposta->baseResponse->getFile()->getPathname());
    }

    public function test_exporta_em_todos_os_formatos_com_os_cabecalhos_certos(): void
    {
        $admin = $this->utilizador('Admin', 'admin@example.com', Perfil::ADMIN_ESCOLA);

        foreach (['excel' => 'spreadsheetml', 'pdf' => 'application/pdf', 'csv' => 'text/csv', 'zip' => 'application/zip'] as $formato => $mime) {
            $resposta = $this->actingAs($admin)->get('/usuarios/exportar?formato='.$formato)->assertOk();
            $this->assertStringContainsString($mime, $resposta->headers->get('content-type'));
            $this->assertStringContainsString('utilizadores-', $resposta->headers->get('content-disposition'));
        }
    }

    public function test_csv_respeita_perfil_pesquisa_e_estado_da_lista(): void
    {
        $admin = $this->utilizador('Admin', 'admin@example.com', Perfil::ADMIN_ESCOLA);
        $this->utilizador('Ana Aluna', 'ana@example.com', Perfil::ALUNO);
        $this->utilizador('Bruno Aluno', 'bruno@example.com', Perfil::ALUNO, 0);
        $this->utilizador('Carla Professora', 'carla@example.com', Perfil::PROFESSOR);

        $alunos = $this->csv('/usuarios/exportar?formato=csv&perfil=alunos', $admin);
        $this->assertStringContainsString('Ana Aluna', $alunos);
        $this->assertStringContainsString('Bruno Aluno', $alunos);
        $this->assertStringNotContainsString('Carla', $alunos);

        $ativos = $this->csv('/usuarios/exportar?formato=csv&perfil=alunos&estado=1', $admin);
        $this->assertStringContainsString('Ana Aluna', $ativos);
        $this->assertStringNotContainsString('Bruno', $ativos);

        $pesquisa = $this->csv('/usuarios/exportar?formato=csv&pesquisa=carla', $admin);
        $this->assertStringContainsString('Carla', $pesquisa);
        $this->assertStringNotContainsString('Ana Aluna', $pesquisa);
        $this->assertStringContainsString('Nome;Email;"Nº de matrícula";Perfil;Estado;"Criado em"', $pesquisa);
    }

    public function test_sem_permissao_ou_com_parametros_invalidos_nao_exporta(): void
    {
        $semPermissoes = $this->utilizador('Sem', 'sem@example.com', null);
        $admin = $this->utilizador('Admin', 'admin@example.com', Perfil::ADMIN_ESCOLA);

        $this->actingAs($semPermissoes)->get('/usuarios/exportar?formato=csv')->assertForbidden();
        $this->actingAs($admin)->get('/usuarios/exportar?formato=docx')->assertStatus(422);
        $this->actingAs($admin)->get('/usuarios/exportar?formato=csv&perfil=xpto')->assertSessionHasErrors('perfil');
        $this->actingAs($admin)->get('/usuarios/exportar')->assertSessionHasErrors('formato');
    }

    public function test_sem_poder_ver_administradores_a_lista_geral_exportada_nao_os_inclui(): void
    {
        $this->utilizador('Admin Escola', 'admin@example.com', Perfil::ADMIN_ESCOLA);
        $this->utilizador('Ana Aluna', 'ana@example.com', Perfil::ALUNO);
        $consulta = app(\Modules\Usuario\Services\UsuarioConsultaService::class);

        $com = collect($consulta->paraExportacao(null, [], 100, true))->pluck('nome');
        $sem = collect($consulta->paraExportacao(null, [], 100, false))->pluck('nome');

        $this->assertTrue($com->contains('Admin Escola'));
        $this->assertFalse($sem->contains('Admin Escola'));
        $this->assertTrue($sem->contains('Ana Aluna'));
    }
}
