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
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Tenancy\CaminhoTenant;
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
            ->assertInertia(fn (Assert $p) => $p->component('Docente/AreaProfessor/Alunos')->has('alunos', 0)->has('nivel')->has('curso')->where('disciplina_nome', $c['disciplina']->nome));

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

    private function matricularInscrito(array $c, Turma $turma, string $nome, string $numero, int $disciplinaId): Aluno
    {
        $pessoa = DadosPessoa::create(['nome_completo' => $nome, 'numero_identificacao' => 'BI' . $numero, 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => Estabelecimento::current()->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => $numero]);
        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $c['ano']->id,
            'numero_registo_matricula' => $numero, 'data_matricula' => '2026-02-01', 'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
        InscricaoDisciplina::create([
            'matricula_id' => $matricula->id,
            'plano_curricular_disciplina_id' => PlanoCurricularDisciplina::where('plano_curricular_id', $c['plano']->id)->where('disciplina_id', $disciplinaId)->firstOrFail()->id,
            'data_inscricao' => '2026-02-01', 'estado' => EstadoInscricaoDisciplinaEnum::INSCRITA->value,
        ]);

        return $aluno;
    }

    public function test_lista_unica_so_tem_alunos_do_professor_e_a_ficha_recusa_os_de_outros(): void
    {
        $c = $this->cenarioDeDocencia();
        $outraTurma = Turma::create(['ano_lectivo_id' => $c['ano']->id, 'nivel_academico_id' => $c['turma']->nivel_academico_id, 'curso_id' => $c['turma']->curso_id, 'codigo' => 'T10B', 'nome' => 'Turma 10B']);
        $meu = $this->matricularInscrito($c, $c['turma'], 'Ana Paula', '2026-0004', $c['disciplina']->id);
        $naoMeuDisciplina = $this->matricularInscrito($c, $c['turma'], 'Bruno Só Português', '2026-0005', $c['outraDisciplina']->id);
        $naoMeuTurma = $this->matricularInscrito($c, $outraTurma, 'Carlos Outra Turma', '2026-0006', $c['disciplina']->id);

        $docente = $this->criarDocente('Prof Lista', comAcesso: true);
        $user = $docente->utilizador();
        $user->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01', 'data_fim' => null,
        ]);
        $this->actingAs($user);

        $this->get('/professor/alunos')->assertInertia(fn (Assert $p) => $p
            ->component('Docente/AreaProfessor/MeusAlunos')
            ->has('alunos', 1)
            ->where('alunos.0.aluno_id', $meu->id)
            ->where('alunos.0.disciplinas', ['Matemática']));

        $this->get('/professor/alunos?q=zzz')->assertInertia(fn (Assert $p) => $p->has('alunos', 0));
        $this->get('/professor/alunos?q=0004')->assertInertia(fn (Assert $p) => $p->has('alunos', 1));

        $this->get("/professor/alunos/{$meu->id}")->assertInertia(fn (Assert $p) => $p
            ->component('Docente/AreaProfessor/FichaAluno')
            ->where('aluno.nome', 'Ana Paula')
            ->missing('aluno.telefone')
            ->missing('aluno.email'));

        // Foto: sem ficheiro, foto_url é null e a rota dá 404; com ficheiro, o professor vê-a; a de um aluno alheio é 403.
        $this->get('/professor/alunos')->assertInertia(fn (Assert $p) => $p->where('alunos.0.foto_url', null));
        $this->get("/professor/alunos/{$meu->id}/foto")->assertNotFound();
        Storage::fake('privado');
        $caminho = CaminhoTenant::para('alunos/fotos/ana.jpg');
        Storage::disk('privado')->put($caminho, 'img');
        $meu->update(['foto_path' => $caminho]);
        $naoMeuDisciplina->update(['foto_path' => $caminho]);
        $this->get('/professor/alunos')->assertInertia(fn (Assert $p) => $p->where('alunos.0.foto_url', route('area-professor.alunos-foto', $meu->id)));
        $this->get("/professor/alunos/{$meu->id}/foto")->assertOk();
        $this->get("/professor/alunos/{$naoMeuDisciplina->id}/foto")->assertForbidden();

        $this->get("/professor/alunos/{$naoMeuDisciplina->id}")->assertForbidden();
        $this->get("/professor/alunos/{$naoMeuTurma->id}")->assertForbidden();
    }

    public function test_perfil_sem_a_permissao_nao_ve_a_lista_de_alunos(): void
    {
        $this->cenarioDeDocencia();
        $u = $this->criarProfessor();
        $u->roles()->sync([Role::where('nome', Perfil::FUNCIONARIO->value)->first()->id]);
        $this->actingAs($u);

        $this->get('/professor/alunos')->assertForbidden();
    }
}
