<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Docente\Models\Docente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Actions\AtualizarUsuarioAction;
use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocenteSincronizacaoTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function criarUtilizador(Perfil $perfil, string $email): User
    {
        return app(UsuarioAction::class)->criar(new UsuarioDTO(
            name: 'Utilizador ' . $email, password: 'segredo123', perfil: $perfil, tipoLogin: TipoLogin::EMAIL,
            email: $email, estado: Estado::ATIVO,
        ));
    }

    public function test_criar_utilizador_professor_pelo_fluxo_normal_cria_o_docente(): void
    {
        $user = $this->criarUtilizador(Perfil::PROFESSOR, 'p1@example.com');

        $docente = Docente::query()->firstOrFail();
        $this->assertSame((int) $user->fresh()->dados_pessoa_id, (int) $docente->dados_pessoa_id);
        $this->assertTrue($docente->temAcesso());
    }

    public function test_criar_utilizador_de_outro_perfil_nao_cria_docente(): void
    {
        $this->criarUtilizador(Perfil::FUNCIONARIO, 'f1@example.com');
        $this->criarUtilizador(Perfil::ADMIN_ESCOLA, 'a1@example.com');

        $this->assertSame(0, Docente::query()->count());
    }

    public function test_editar_utilizador_para_professor_cria_o_docente_uma_so_vez(): void
    {
        $user = $this->criarUtilizador(Perfil::FUNCIONARIO, 'f2@example.com');
        $this->assertSame(0, Docente::query()->count());

        $actualizar = fn () => app(AtualizarUsuarioAction::class)->atualizar($user->fresh(), [
            'name' => $user->name, 'email' => $user->email, 'perfil' => Perfil::PROFESSOR->slug(),
        ]);
        $actualizar();
        $actualizar();

        $this->assertSame(1, Docente::query()->count());
    }

    public function test_comando_cria_docentes_para_professores_existentes_e_e_idempotente(): void
    {
        $this->criarProfessor('p1@example.com');
        $this->criarProfessor('p2@example.com');
        $this->criarDocente('Sem conta');
        $semPessoa = $this->criarProfessor('p3@example.com');
        $this->assertSame(1, Docente::query()->count());

        $this->artisan('docentes:sincronizar', ['--tenant' => $this->tenant->codigo])
            ->expectsOutputToContain('3 docente(s) criado(s)')
            ->assertSuccessful();

        $this->assertSame(4, Docente::query()->count());
        $this->assertNotNull($semPessoa->fresh()->dados_pessoa_id);

        $this->artisan('docentes:sincronizar', ['--tenant' => $this->tenant->codigo])
            ->expectsOutputToContain('0 docente(s) criado(s)')
            ->assertSuccessful();

        $this->assertSame(4, Docente::query()->count());
    }

    public function test_comando_exige_tenant_e_nao_toca_noutros_tenants(): void
    {
        $this->artisan('docentes:sincronizar')->assertFailed();

        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->criarProfessor('b@example.com'));
        $this->criarProfessor('a@example.com');

        $this->artisan('docentes:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertSame(1, Docente::query()->count());
        $this->assertSame(0, $this->noTenant($outro, fn () => Docente::query()->count()));

        $this->artisan('docentes:sincronizar', ['--todos' => true])->assertSuccessful();
        $this->assertSame(1, $this->noTenant($outro, fn () => Docente::query()->count()));
    }
}
