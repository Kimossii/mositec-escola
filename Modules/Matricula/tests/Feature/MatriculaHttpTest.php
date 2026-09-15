<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class MatriculaHttpTest extends TestCase
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

    private function actingAsProfessor(): User
    {
        $professor = User::firstOrCreate(
            ['email' => 'professor@example.com'],
            ['name' => 'Professor', 'password' => Hash::make('segredo123')],
        );
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->actingAs($professor);

        return $professor;
    }

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    private function criarAnoLectivo(Estabelecimento $estabelecimento): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => '2026',
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
    }

    private function criarNivelAcademico(Estabelecimento $estabelecimento): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
            'etapa_ensino' => 1,
        ]);
    }

    private function criarTurma(AnoLectivo $anoLectivo, NivelAcademico $nivel): Turma
    {
        return Turma::create([
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T' . random_int(1000, 9999),
            'nome' => 'Turma Teste',
        ]);
    }

    private function criarAluno(Estabelecimento $estabelecimento): Aluno
    {
        $pessoa = DadosPessoal::create([
            'nome_completo' => 'Aluno Teste',
            'numero_identificacao' => 'BI' . random_int(1000, 9999),
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);

        return Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => '2026-' . random_int(1000, 9999),
        ]);
    }

    public function test_cria_matricula_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);

        $this->post(route('matriculas.store', $aluno), [
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'data_matricula' => '2026-02-01',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('matriculas', [
            'aluno_id' => $aluno->id,
            'turma_id' => $turma->id,
        ]);
    }

    public function test_criar_matricula_sem_enquadramento_academico_assume_o_contexto_da_turma(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        // Sem enquadramento prévio — a matrícula deve assumi-lo automaticamente.

        $this->post(route('matriculas.store', $aluno), [
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'data_matricula' => '2026-02-01',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('matriculas', ['aluno_id' => $aluno->id]);
        $this->assertDatabaseHas('aluno_enquadramentos_academicos', [
            'aluno_id' => $aluno->id,
            'nivel_academico_id' => $nivel->id,
        ]);
    }

    public function test_criar_matricula_com_enquadramento_incompativel_devolve_erro_de_validacao_em_vez_de_500(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $outroNivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '2C',
            'nome' => '2ª Classe',
            'ordem' => 2,
            'etapa_ensino' => 1,
        ]);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $outroNivel->id);
        // Aluno já enquadrado noutro nível — este é o caso real que rebentava em 500.

        $this->post(route('matriculas.store', $aluno), [
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'data_matricula' => '2026-02-01',
        ])->assertSessionHasErrors('turma_id')->assertRedirect();

        $this->assertDatabaseMissing('matriculas', ['aluno_id' => $aluno->id]);
    }

    public function test_altera_estado_da_matricula_via_http(): void
    {
        $staff = $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001',
            'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        $this->patch(route('matriculas.alterar-estado', [$aluno, $matricula]), [
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $matricula->refresh();
        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->estado);
        $this->assertSame($staff->id, $matricula->editado_por);
    }

    public function test_elimina_matricula_pendente_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001', 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        $this->delete(route('matriculas.destroy', [$aluno, $matricula]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSoftDeleted('matriculas', ['id' => $matricula->id]);
    }

    public function test_rejeita_eliminar_matricula_activa_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001', 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        $this->delete(route('matriculas.destroy', [$aluno, $matricula]))
            ->assertSessionHasErrors('matricula')->assertRedirect();

        $this->assertDatabaseHas('matriculas', ['id' => $matricula->id, 'deleted_at' => null]);
    }

    public function test_professor_recebe_403_ao_criar_matricula(): void
    {
        $this->actingAsProfessor();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);

        $this->post(route('matriculas.store', $aluno), [
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'data_matricula' => '2026-02-01',
        ])->assertForbidden();
    }

    public function test_aluno_show_expoe_matriculas_e_turmas_disponiveis(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);

        $this->get(route('alunos.show', $aluno))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Show')
            ->has('matriculas.data')
            ->has('turmasDisponiveis', 1)
            ->has('anosLectivosComMatricula')
        );
    }

    public function test_aluno_show_pagina_e_filtra_matriculas_por_ano_lectivo_activo_por_omissao(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivoAtivo = $this->criarAnoLectivo($estabelecimento, '2026');
        $anoLectivoEncerrado = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2025',
            'data_inicio' => '2025-01-01', 'data_fim' => '2025-12-31', 'estado' => \Modules\AnoLectivo\Enums\EstadoAnoLectivo::ENCERRADO,
        ]);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turmaAtiva = $this->criarTurma($anoLectivoAtivo, $nivel);
        $turmaEncerrada = $this->criarTurma($anoLectivoEncerrado, $nivel);
        $aluno = $this->criarAluno($estabelecimento);

        Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turmaAtiva->id, 'ano_lectivo_id' => $anoLectivoAtivo->id,
            'numero_registo_matricula' => '2026-0001', 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);
        Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turmaEncerrada->id, 'ano_lectivo_id' => $anoLectivoEncerrado->id,
            'numero_registo_matricula' => '2025-0001', 'data_matricula' => '2025-02-01',
            'estado' => EstadoMatriculaEnum::CONCLUIDA->value,
        ]);

        // Sem filtro explícito — só mostra o ano lectivo activo.
        $this->get(route('alunos.show', $aluno))->assertInertia(fn (Assert $page) => $page
            ->has('matriculas.data', 1)
            ->where('matriculas.data.0.ano_lectivo_id', $anoLectivoAtivo->id)
            ->has('anosLectivosComMatricula', 2)
        );

        // Explicitamente "todos".
        $this->get(route('alunos.show', ['aluno' => $aluno, 'ano_lectivo_id' => '']))->assertInertia(fn (Assert $page) => $page
            ->has('matriculas.data', 2)
        );
    }

    public function test_lista_global_de_matriculas_com_filtro_por_turma(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turmaA = $this->criarTurma($anoLectivo, $nivel);
        $turmaB = $this->criarTurma($anoLectivo, $nivel);
        $alunoA = $this->criarAluno($estabelecimento);
        $alunoB = $this->criarAluno($estabelecimento);

        Matricula::create([
            'aluno_id' => $alunoA->id, 'turma_id' => $turmaA->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001', 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);
        Matricula::create([
            'aluno_id' => $alunoB->id, 'turma_id' => $turmaB->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0002', 'data_matricula' => '2026-02-02',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        $this->get(route('matriculas.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Matricula/Index')
            ->has('matriculas.data', 2)
        );

        $this->get(route('matriculas.index', ['turma_id' => $turmaA->id]))->assertInertia(fn (Assert $page) => $page
            ->component('Matricula/Index')
            ->has('matriculas.data', 1)
            ->where('matriculas.data.0.turma_id', $turmaA->id)
        );
    }

    public function test_professor_recebe_403_na_listagem_global(): void
    {
        $this->actingAsProfessor();

        $this->get(route('matriculas.index'))->assertForbidden();
    }

    public function test_listagem_global_pagina_os_resultados(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);

        for ($i = 1; $i <= 25; $i++) {
            $pessoa = DadosPessoal::create([
                'nome_completo' => "Aluno {$i}",
                'numero_identificacao' => "BI-PAG-{$i}",
                'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
            ]);
            $aluno = Aluno::create([
                'estabelecimento_id' => $estabelecimento->id,
                'dados_pessoa_id' => $pessoa->id,
                'numero_matricula' => "2026-PAG-{$i}",
            ]);
            Matricula::create([
                'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoLectivo->id,
                'numero_registo_matricula' => "2026-" . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'data_matricula' => '2026-02-01',
                'estado' => EstadoMatriculaEnum::PENDENTE->value,
            ]);
        }

        $this->get(route('matriculas.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Matricula/Index')
            ->has('matriculas.data', 10)
            ->where('matriculas.current_page', 1)
            ->where('matriculas.last_page', 3)
            ->where('matriculas.total', 25)
        );

        $this->get(route('matriculas.index', ['page' => 3]))->assertInertia(fn (Assert $page) => $page
            ->has('matriculas.data', 5)
            ->where('matriculas.current_page', 3)
        );
    }

    public function test_listagem_global_pesquisa_por_numero_matricula_nome_e_numero_identificacao(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);

        $pessoaA = DadosPessoal::create([
            'nome_completo' => 'Ana Maria Silva', 'numero_identificacao' => 'BI12345', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);
        $alunoA = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoaA->id, 'numero_matricula' => '2026-AAAA',
        ]);
        $pessoaB = DadosPessoal::create([
            'nome_completo' => 'Bruno Costa', 'numero_identificacao' => 'BI99999', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);
        $alunoB = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoaB->id, 'numero_matricula' => '2026-BBBB',
        ]);

        Matricula::create([
            'aluno_id' => $alunoA->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001', 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);
        Matricula::create([
            'aluno_id' => $alunoB->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0002', 'data_matricula' => '2026-02-02',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        $this->get(route('matriculas.index', ['pesquisa' => 'Ana Maria']))->assertInertia(fn (Assert $page) => $page
            ->has('matriculas.data', 1)
            ->where('matriculas.data.0.aluno_id', $alunoA->id)
        );

        $this->get(route('matriculas.index', ['pesquisa' => 'BI99999']))->assertInertia(fn (Assert $page) => $page
            ->has('matriculas.data', 1)
            ->where('matriculas.data.0.aluno_id', $alunoB->id)
        );

        $this->get(route('matriculas.index', ['pesquisa' => '2026-AAAA']))->assertInertia(fn (Assert $page) => $page
            ->has('matriculas.data', 1)
            ->where('matriculas.data.0.aluno_id', $alunoA->id)
        );
    }

    public function test_alterar_estado_via_http_preenche_data_fim(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);

        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001', 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        $this->patch(route('matriculas.alterar-estado', [$aluno, $matricula]), [
            'estado' => EstadoMatriculaEnum::CANCELADA->value,
            'data_fim' => '2026-05-10',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('2026-05-10', $matricula->fresh()->data_fim->toDateString());
    }

    public function test_renova_matricula_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivoAtual = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2026',
            'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $anoLectivoSeguinte = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id, 'nome' => '2027',
            'data_inicio' => '2027-01-01', 'data_fim' => '2027-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel5 = $this->criarNivelAcademico($estabelecimento);
        $nivel6 = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id, 'codigo' => '6C', 'nome' => '6ª Classe',
            'ordem' => $nivel5->ordem + 1, 'etapa_ensino' => 1,
        ]);
        $turmaAtual = $this->criarTurma($anoLectivoAtual, $nivel5);
        $this->criarTurma($anoLectivoSeguinte, $nivel6);
        $aluno = $this->criarAluno($estabelecimento);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel5->id);

        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turmaAtual->id, 'ano_lectivo_id' => $anoLectivoAtual->id,
            'numero_registo_matricula' => '2025-9999', 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::CONCLUIDA->value,
        ]);

        $this->post(route('matriculas.renovar', [$aluno, $matricula]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('matriculas', [
            'aluno_id' => $aluno->id,
            'ano_lectivo_id' => $anoLectivoSeguinte->id,
        ]);
    }
}
