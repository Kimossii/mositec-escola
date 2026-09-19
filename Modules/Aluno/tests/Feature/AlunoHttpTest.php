<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlunoHttpTest extends TestCase
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

    private function criarEstabelecimento(bool $activo = true): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => $activo]);
    }

    public function test_cria_aluno_via_http_infere_estabelecimento_actual_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('alunos.store'), [
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI0001',
            'data_nascimento' => '2010-05-01',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $aluno = Aluno::firstWhere('dados_pessoa_id', DadosPessoa::firstWhere('numero_identificacao', 'BI0001')?->id);
        $this->assertNotNull($aluno);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $aluno->numero_matricula);
        $this->assertSame($staff->id, $aluno->criado_por);
        $this->assertSame(Estabelecimento::current()->id, $aluno->estabelecimento_id);
        $this->assertSame('Ana Silva', $aluno->dadosPessoa->nome_completo);
    }

    public function test_cria_aluno_via_http_com_foto(): void
    {
        Storage::fake('public');
        $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('alunos.store'), [
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI0001',
            'data_nascimento' => '2010-05-01',
            'foto' => UploadedFile::fake()->image('foto.jpg'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $aluno = Aluno::firstWhere('dados_pessoa_id', DadosPessoa::firstWhere('numero_identificacao', 'BI0001')?->id);
        $this->assertNotNull($aluno->foto_path);
        Storage::disk('public')->assertExists($aluno->foto_path);
    }

    public function test_actualiza_aluno_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->put(route('alunos.update', $aluno), [
            'nome_completo' => 'Ana Silva Santos',
            'data_nascimento' => '2010-05-01',
            'numero_identificacao' => 'BI0001',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Ana Silva Santos', $aluno->dadosPessoa->fresh()->nome_completo);
        $this->assertSame('2026-0001', $aluno->fresh()->numero_matricula);
    }

    public function test_actualiza_numero_identificacao_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->put(route('alunos.update', $aluno), [
            'nome_completo' => 'Ana Silva',
            'data_nascimento' => '2010-05-01',
            'numero_identificacao' => 'BI9999',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('BI9999', $pessoa->fresh()->numero_identificacao);
    }

    public function test_actualiza_aluno_falha_com_numero_identificacao_ja_usado_por_outra_pessoa(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        DadosPessoa::create(['nome_completo' => 'Bruno Costa', 'numero_identificacao' => 'BI0002', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->put(route('alunos.update', $aluno), [
            'nome_completo' => 'Ana Silva',
            'data_nascimento' => '2010-05-01',
            'numero_identificacao' => 'BI0002',
        ])->assertSessionHasErrors('numero_identificacao');

        $this->assertSame('BI0001', $pessoa->fresh()->numero_identificacao);
    }

    public function test_actualiza_aluno_falha_sem_data_nascimento(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->put(route('alunos.update', $aluno), [
            'nome_completo' => 'Ana Silva Santos',
        ])->assertSessionHasErrors('data_nascimento');
    }

    public function test_altera_estado_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->patch(route('alunos.alterar-estado', $aluno), ['estado' => Estado::INATIVO->value])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(Estado::INATIVO->value, $aluno->fresh()->estado);
    }

    public function test_index_expoe_apenas_alunos_do_estabelecimento_actual(): void
    {
        $this->actingAsStaff();
        $actual = $this->criarEstabelecimento();
        $pessoa1 = DadosPessoa::create(['nome_completo' => 'Ana', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $actual->id, 'dados_pessoa_id' => $pessoa1->id, 'numero_matricula' => '2026-0001']);

        $outra = $this->criarEstabelecimento(false);
        $pessoa2 = DadosPessoa::create(['nome_completo' => 'Bruno', 'numero_identificacao' => 'BI0002', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $outra->id, 'dados_pessoa_id' => $pessoa2->id, 'numero_matricula' => '2026-0002']);

        $this->get(route('alunos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Index')
            ->has('alunos.data', 1)
            ->where('alunos.data.0.numero_matricula', '2026-0001')
        );
    }

    public function test_index_filtra_por_pesquisa_e_devolve_listas_de_apoio(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa1 = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa1->id, 'numero_matricula' => '2026-0001']);
        $pessoa2 = DadosPessoa::create(['nome_completo' => 'Bruno Costa', 'numero_identificacao' => 'BI0002', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa2->id, 'numero_matricula' => '2026-0002']);

        $this->get(route('alunos.index', ['pesquisa' => 'Ana Silva']))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Index')
            ->has('alunos.data', 1)
            ->where('alunos.data.0.numero_matricula', '2026-0001')
            ->where('filtros.pesquisa', 'Ana Silva')
            ->has('anosLectivosDisponiveis')
            ->has('turmasDisponiveis')
            ->has('cursosDisponiveis')
            ->has('niveisAcademicosDisponiveis')
        );
    }

    public function test_index_ano_lectivo_por_omissao_e_o_activo(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoActivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $anoAnterior = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2025/2026', 'data_inicio' => '2025-09-01', 'data_fim' => '2026-07-31', 'estado' => EstadoAnoLectivo::ENCERRADO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N1', 'nome' => 'Nível 1', 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => 1]);
        $turmaActual = Turma::create(['ano_lectivo_id' => $anoActivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma A']);
        $turmaAnterior = Turma::create(['ano_lectivo_id' => $anoAnterior->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T2', 'nome' => 'Turma B']);

        $pessoa1 = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $alunoActual = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa1->id, 'numero_matricula' => '2026-0001']);
        Matricula::create(['aluno_id' => $alunoActual->id, 'turma_id' => $turmaActual->id, 'ano_lectivo_id' => $anoActivo->id, 'numero_registo_matricula' => 'M0001', 'data_matricula' => now(), 'estado' => EstadoMatriculaEnum::ACTIVA->value]);

        $pessoa2 = DadosPessoa::create(['nome_completo' => 'Bruno Costa', 'numero_identificacao' => 'BI0002', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $alunoAnterior = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa2->id, 'numero_matricula' => '2026-0002']);
        Matricula::create(['aluno_id' => $alunoAnterior->id, 'turma_id' => $turmaAnterior->id, 'ano_lectivo_id' => $anoAnterior->id, 'numero_registo_matricula' => 'M0002', 'data_matricula' => now(), 'estado' => EstadoMatriculaEnum::ACTIVA->value]);

        $this->get(route('alunos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Index')
            ->where('filtros.ano_lectivo_id', $anoActivo->id)
            ->has('alunos.data', 1)
            ->where('alunos.data.0.numero_matricula', '2026-0001')
        );
    }

    public function test_index_ano_lectivo_vazio_explicito_mostra_todos(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $pessoa1 = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa1->id, 'numero_matricula' => '2026-0001']);

        $this->get(route('alunos.index', ['ano_lectivo_id' => '']))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Index')
            ->where('filtros.ano_lectivo_id', null)
            ->has('alunos.data', 1)
        );
    }

    public function test_show_expoe_o_aluno(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->get(route('alunos.show', $aluno))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Show')
            ->where('aluno.numero_matricula', '2026-0001')
        );
    }

    public function test_show_expoe_a_matricula_actual_do_aluno(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoActivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N1', 'nome' => 'Nível 1', 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => 1]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $turno = Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);
        $turma = Turma::create(['ano_lectivo_id' => $anoActivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'turno_id' => $turno->id, 'codigo' => 'T1', 'nome' => 'Turma A']);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoActivo->id,
            'numero_registo_matricula' => 'M0001',
            'data_matricula' => now(),
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        $this->get(route('alunos.show', $aluno))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Show')
            ->where('matriculaActual.turma.curso.nome', 'Informática')
            ->where('matriculaActual.turma.nivel_academico.nome', 'Nível 1')
            ->where('matriculaActual.turma.turno.nome', 'Manhã')
            ->where('matriculaActual.ano_lectivo.nome', '2026/2027')
        );
    }

    public function test_resumo_academico_expoe_matricula_actual_e_ultimas_matriculas(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoActivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N1', 'nome' => 'Nível 1', 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => 1]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $turma = Turma::create(['ano_lectivo_id' => $anoActivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma A']);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoActivo->id,
            'numero_registo_matricula' => 'M0001', 'data_matricula' => now(), 'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        $resposta = $this->get(route('alunos.resumo-academico', $aluno));

        $resposta->assertOk();
        $resposta->assertJsonPath('matriculaActual.turma.curso.nome', 'Informática');
        $resposta->assertJsonCount(1, 'ultimasMatriculas');
        $resposta->assertJsonPath('ultimasMatriculas.0.numero_registo_matricula', 'M0001');
    }

    public function test_show_expoe_as_outras_matriculas_activas_quando_o_aluno_tem_mais_de_uma(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoActivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N1', 'nome' => 'Nível 1', 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => 1]);
        $cursoA = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $cursoB = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'GES', 'nome' => 'Gestão']);
        $turmaA = Turma::create(['ano_lectivo_id' => $anoActivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $cursoA->id, 'codigo' => 'T1', 'nome' => 'Turma A']);
        $turmaB = Turma::create(['ano_lectivo_id' => $anoActivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $cursoB->id, 'codigo' => 'T2', 'nome' => 'Turma B']);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        Matricula::create(['aluno_id' => $aluno->id, 'turma_id' => $turmaA->id, 'ano_lectivo_id' => $anoActivo->id, 'numero_registo_matricula' => 'M0001', 'data_matricula' => '2026-09-01', 'estado' => EstadoMatriculaEnum::ACTIVA->value]);
        Matricula::create(['aluno_id' => $aluno->id, 'turma_id' => $turmaB->id, 'ano_lectivo_id' => $anoActivo->id, 'numero_registo_matricula' => 'M0002', 'data_matricula' => '2026-09-05', 'estado' => EstadoMatriculaEnum::ACTIVA->value]);

        $this->get(route('alunos.show', $aluno))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Show')
            ->where('matriculaActual.turma.curso.nome', 'Gestão')
            ->where('outrasMatriculasActivas.0.turma.curso.nome', 'Informática')
        );
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->post(route('alunos.store'), ['nome_completo' => 'X', 'numero_identificacao' => 'BI9999'])->assertForbidden();
        $this->put(route('alunos.update', $aluno), ['nome_completo' => 'Y'])->assertForbidden();
        $this->patch(route('alunos.alterar-estado', $aluno), ['estado' => 0])->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('alunos.index'))->assertForbidden();
    }
}
