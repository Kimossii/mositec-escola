<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Tests\TestCase;

class AreaProfessorHttpTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow('2026-06-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_professor_ve_as_suas_turmas_e_so_acede_aos_alunos_do_que_lecciona(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente('Prof Teste', comAcesso: true);
        $user = $docente->utilizador();
        $user->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01', 'data_fim' => null,
        ]);
        $this->actingAs($user);

        $this->get('/professor/turmas')->assertInertia(fn (Assert $p) => $p
            ->component('Docente/AreaProfessor/Index')
            ->has('turmas', 1)
            ->where('turmas.0.disciplinas.0.disciplina_id', $c['disciplina']->id));

        $this->get("/professor/turmas/{$c['turma']->id}/disciplinas/{$c['disciplina']->id}/alunos")
            ->assertInertia(fn (Assert $p) => $p->component('Docente/AreaProfessor/Alunos')->has('alunos', 0));

        $this->get("/professor/turmas/{$c['turma']->id}/disciplinas/{$c['outraDisciplina']->id}/alunos")->assertForbidden();
    }

    public function test_perfil_sem_a_permissao_nao_entra(): void
    {
        $this->cenarioDeDocencia();
        $u = $this->criarProfessor();
        $u->roles()->sync([Role::where("nome", Perfil::FUNCIONARIO->value)->first()->id]);
        $this->actingAs($u);

        $this->get('/professor/turmas')->assertForbidden();
    }
}
