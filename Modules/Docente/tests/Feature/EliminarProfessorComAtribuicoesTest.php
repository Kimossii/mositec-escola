<?php

namespace Modules\Docente\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Support\DependenciasRegistadasDoUtilizador;
use Modules\Docente\Actions\AtribuirDocenteAction;
use Modules\Docente\Actions\EliminarAtribuicaoAction;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Support\DependenciasDoUtilizadorDocente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** Um professor com atribuições não se elimina (erro legível em vez de violação da FK); sem elas, elimina-se. */
class EliminarProfessorComAtribuicoesTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsAdmin(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('segredo123')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);
        $this->actingAs($admin);
    }

    private function atribuir(array $c, User $professor, string $inicio): AtribuicaoDocente
    {
        return app(AtribuirDocenteAction::class)->executar(new AtribuicaoDocenteDTO(
            $professor->id, $c['turma']->id, $c['disciplina']->id, PapelDocente::TITULAR, $inicio, null,
        ));
    }

    public function test_professor_com_atribuicao_nao_se_elimina_e_recebe_mensagem(): void
    {
        $c = $this->cenarioDeDocencia();
        $professor = $this->criarProfessor();
        $this->atribuir($c, $professor, '2026-03-01');
        $this->actingAsAdmin();

        $this->delete("/usuarios/{$professor->id}")
            ->assertSessionHasErrors(['utilizador' => DependenciasDoUtilizadorDocente::MOTIVO]);

        $this->assertDatabaseHas('users', ['id' => $professor->id]);
    }

    public function test_professor_cuja_atribuicao_agendada_foi_eliminada_ja_se_elimina(): void
    {
        $c = $this->cenarioDeDocencia();
        $professor = $this->criarProfessor();
        app(EliminarAtribuicaoAction::class)->executar($this->atribuir($c, $professor, '2026-09-01'));
        $this->actingAsAdmin();

        $this->delete("/usuarios/{$professor->id}")->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $professor->id]);
    }

    public function test_professor_sem_atribuicoes_elimina_como_antes(): void
    {
        $this->cenarioDeDocencia();
        $professor = $this->criarProfessor();
        $this->actingAsAdmin();

        $this->delete("/usuarios/{$professor->id}")->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $professor->id]);
    }

    public function test_esta_registada_com_a_etiqueta_do_utilizador(): void
    {
        $classes = collect(app()->tagged(DependenciasRegistadasDoUtilizador::ETIQUETA))->map(fn ($d) => $d::class);

        $this->assertContains(DependenciasDoUtilizadorDocente::class, $classes->all());
    }
}
