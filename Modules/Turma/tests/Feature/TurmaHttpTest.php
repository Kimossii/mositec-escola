<?php

namespace Modules\Turma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class TurmaHttpTest extends TestCase
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
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'is_active' => true]);

        foreach ([EtapaEnsinoEnum::PRIMARIO, EtapaEnsinoEnum::SECUNDARIO] as $etapa) {
            EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => $etapa]);
        }

        return $estabelecimento;
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

    private function criarCurso(Estabelecimento $estabelecimento): Curso
    {
        return Curso::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => 'INF',
            'nome' => 'Informática',
        ]);
    }

    private function criarNivelAcademico(Estabelecimento $estabelecimento, EtapaEnsinoEnum $etapa = EtapaEnsinoEnum::SECUNDARIO): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
            'etapa_ensino' => $etapa,
        ]);
    }

    public function test_cria_nivel_academico_via_http_infere_estabelecimento_actual_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('niveis-academicos.store'), [
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
            'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $nivel = NivelAcademico::firstWhere('codigo', '1C');
        $this->assertNotNull($nivel);
        $this->assertSame($staff->id, $nivel->criado_por);
        $this->assertSame(Estabelecimento::current()->id, $nivel->estabelecimento_id);
        $this->assertSame(1, $nivel->estado);
        $this->assertSame('Ativo', $nivel->estado_descricao);
        $this->assertSame(EtapaEnsinoEnum::PRIMARIO, $nivel->etapa_ensino);
        $this->assertSame('Ensino Primário', $nivel->etapa_ensino_descricao);
    }

    public function test_atualiza_nivel_academico_via_http_com_nova_etapa_ensino(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $nivel = $this->criarNivelAcademico($estabelecimento, EtapaEnsinoEnum::PRIMARIO);

        $this->put(route('niveis-academicos.update', $nivel), [
            'codigo' => $nivel->codigo,
            'nome' => $nivel->nome,
            'ordem' => $nivel->ordem,
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $nivel->refresh();
        $this->assertSame(EtapaEnsinoEnum::SECUNDARIO, $nivel->etapa_ensino);
        $this->assertSame('Ensino Secundário', $nivel->etapa_ensino_descricao);
    }

    public function test_criar_nivel_academico_sem_etapa_ensino_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('niveis-academicos.store'), [
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
        ])->assertSessionHasErrors('etapa_ensino');
    }

    public function test_criar_nivel_academico_com_etapa_ensino_invalida_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('niveis-academicos.store'), [
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
            'etapa_ensino' => 99,
        ])->assertSessionHasErrors('etapa_ensino');
    }

    public function test_criar_nivel_academico_com_etapa_nao_configurada_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('niveis-academicos.store'), [
            'codigo' => 'SUP1',
            'nome' => '1º Ano Universitário',
            'ordem' => 1,
            'etapa_ensino' => EtapaEnsinoEnum::SUPERIOR->value,
        ])->assertSessionHasErrors('etapa_ensino');
    }

    public function test_cria_turno_via_http_infere_estabelecimento_actual(): void
    {
        $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('turnos.store'), [
            'nome' => 'Manhã',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $turno = Turno::firstWhere('nome', 'Manhã');
        $this->assertNotNull($turno);
        $this->assertSame(Estabelecimento::current()->id, $turno->estabelecimento_id);
        $this->assertSame('Ativo', $turno->estado_descricao);
    }

    public function test_turno_com_mesmo_nome_e_permitido_em_estabelecimentos_diferentes(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'is_active' => true]);
        $turnoA = Turno::create(['estabelecimento_id' => $estabelecimentoA->id, 'nome' => 'Manhã']);

        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'is_active' => false]);
        $turnoB = Turno::create(['estabelecimento_id' => $estabelecimentoB->id, 'nome' => 'Manhã']);

        $this->assertNotSame($turnoA->id, $turnoB->id);
        $this->assertDatabaseHas('turnos', ['id' => $turnoA->id, 'nome' => 'Manhã']);
        $this->assertDatabaseHas('turnos', ['id' => $turnoB->id, 'nome' => 'Manhã']);
    }

    public function test_nao_elimina_turno_associado_a_turma(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turno = Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);
        $curso = $this->criarCurso($estabelecimento);
        Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'turno_id' => $turno->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->delete(route('turnos.destroy', $turno))->assertSessionHasErrors('turno');
        $this->assertDatabaseHas('turnos', ['id' => $turno->id]);
    }

    public function test_nao_elimina_nivel_academico_associado_a_turma(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->delete(route('niveis-academicos.destroy', $nivel))->assertSessionHasErrors('nivelAcademico');
        $this->assertDatabaseHas('niveis_academicos', ['id' => $nivel->id]);
    }

    public function test_nao_elimina_nivel_academico_associado_a_plano_curricular(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $nivel = $this->criarNivelAcademico($estabelecimento);
        PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'PLI',
            'nome' => 'Plano Informática',
        ]);

        $this->delete(route('niveis-academicos.destroy', $nivel))->assertSessionHasErrors('nivelAcademico');
        $this->assertDatabaseHas('niveis_academicos', ['id' => $nivel->id]);
    }

    public function test_elimina_turno_associado_a_turma_soft_deleted(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turno = Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'turno_id' => $turno->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $turma->delete();

        $this->delete(route('turnos.destroy', $turno))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSoftDeleted('turnos', ['id' => $turno->id]);
    }

    public function test_elimina_nivel_academico_associado_a_turma_soft_deleted(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $turma->delete();

        $this->delete(route('niveis-academicos.destroy', $nivel))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSoftDeleted('niveis_academicos', ['id' => $nivel->id]);
    }

    public function test_cria_turma_via_http_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);

        $this->post(route('turmas.store'), [
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $turma = Turma::firstWhere('codigo', 'T1');
        $this->assertNotNull($turma);
        $this->assertSame($staff->id, $turma->criado_por);
        $this->assertSame(1, $turma->estado);
        $this->assertSame('Ativo', $turma->estado_descricao);
    }

    public function test_criar_turma_sem_curso_id_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);

        $this->post(route('turmas.store'), [
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasErrors('curso_id');
    }

    public function test_editar_turma_sem_curso_id_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->put(route('turmas.update', $turma), [
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasErrors('curso_id');
    }

    public function test_criar_turma_sem_curso_id_e_permitido_quando_nivel_nao_exige_curso(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento, EtapaEnsinoEnum::PRIMARIO);

        $this->post(route('turmas.store'), [
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $turma = Turma::firstWhere('codigo', 'T1');
        $this->assertNotNull($turma);
        $this->assertNull($turma->curso_id);
    }

    public function test_editar_turma_sem_curso_id_e_permitido_quando_nivel_nao_exige_curso(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento, EtapaEnsinoEnum::PRIMARIO);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->put(route('turmas.update', $turma), [
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($turma->fresh()->curso_id);
    }

    public function test_altera_estado_da_turma_via_http_e_sincroniza_descricao(): void
    {
        $staff = $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->patch(route('turmas.alterar-estado', $turma), [
            'estado' => Estado::INATIVO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $turma->refresh();
        $this->assertSame(Estado::INATIVO->value, $turma->estado);
        $this->assertSame('Inativo', $turma->estado_descricao);
        $this->assertSame($staff->id, $turma->editado_por);
    }

    public function test_elimina_turma_via_http_com_soft_delete(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->delete(route('turmas.destroy', $turma))->assertRedirect();

        $this->assertSoftDeleted('turmas', ['id' => $turma->id]);
    }

    public function test_index_da_turma_expoe_opcoes_de_formulario(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $this->criarAnoLectivo($estabelecimento);
        $this->criarNivelAcademico($estabelecimento);
        Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);

        $this->get(route('turmas.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Turma/Turmas/Index')
            ->has('turmas')
            ->has('anoLectivos', 1)
            ->has('niveisAcademicos', 1)
            ->has('turnos', 1)
        );
    }

    public function test_index_da_turma_expoe_cursos_nas_opcoes_de_formulario(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $this->criarAnoLectivo($estabelecimento);
        $this->criarNivelAcademico($estabelecimento);
        Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);
        $this->criarCurso($estabelecimento);

        $this->get(route('turmas.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Turma/Turmas/Index')
            ->has('cursos', 1)
        );
    }

    public function test_show_da_turma_carrega_relacoes_e_salas_associadas(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $sala = \Modules\Infraestrutura\Models\Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => 0]);

        $this->post(route('turmas.salas.store', $turma), [
            'sala_id' => $sala->id,
            'inicio' => '2026-01-01',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->get(route('turmas.show', $turma))->assertInertia(fn (Assert $page) => $page
            ->component('Turma/Turmas/Show')
            ->where('turma.nivel_academico.nome', '1ª Classe')
            ->has('turma.turma_salas', 1)
            ->where('turma.turma_salas.0.sala.codigo', 'A101')
        );
    }

    public function test_atualiza_sala_associada_a_turma_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $salaErrada = \Modules\Infraestrutura\Models\Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => 0]);
        $salaCorreta = \Modules\Infraestrutura\Models\Sala::create(['codigo' => 'A102', 'nome' => 'Sala 102', 'tipo' => 0]);
        $turmaSala = $turma->turmaSalas()->create(['sala_id' => $salaErrada->id, 'inicio' => '2026-01-01']);

        $this->put(route('turmas.salas.update', [$turma, $turmaSala]), [
            'sala_id' => $salaCorreta->id,
            'inicio' => '2026-01-01',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($salaCorreta->id, $turmaSala->fresh()->sala_id);
    }

    public function test_atualizar_sala_de_outra_turma_devolve_404(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $turmaA = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'TA', 'nome' => 'Turma A']);
        $turmaB = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'TB', 'nome' => 'Turma B']);
        $sala = \Modules\Infraestrutura\Models\Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => 0]);
        $turmaSalaDeA = $turmaA->turmaSalas()->create(['sala_id' => $sala->id, 'inicio' => '2026-01-01']);

        $this->put(route('turmas.salas.update', [$turmaB, $turmaSalaDeA]), [
            'sala_id' => $sala->id,
            'inicio' => '2026-02-01',
        ])->assertNotFound();
    }

    public function test_encerrar_sala_de_outra_turma_devolve_404(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $turmaA = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'TA', 'nome' => 'Turma A']);
        $turmaB = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'TB', 'nome' => 'Turma B']);
        $sala = \Modules\Infraestrutura\Models\Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => 0]);
        $turmaSalaDeA = $turmaA->turmaSalas()->create(['sala_id' => $sala->id, 'inicio' => '2026-01-01']);

        $this->patch(route('turmas.salas.encerrar', [$turmaB, $turmaSalaDeA]), [
            'fim' => '2026-02-01',
        ])->assertNotFound();
    }

    public function test_adiciona_horario_ao_turno_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $horario = \Modules\Core\Models\Horario::create(['nome' => 'Bloco 1', 'hora_inicio' => '07:00', 'hora_fim' => '07:45']);
        $turno = Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);

        $this->post(route('turnos.horarios.store', $turno), [
            'horario_id' => $horario->id,
            'ordem' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->get(route('turnos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Turma/Turnos/Index')
            ->has('turnos.0.turno_horarios', 1)
            ->where('turnos.0.turno_horarios.0.horario.nome', 'Bloco 1')
        );
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();

        $this->post(route('turmas.store'), ['codigo' => 'T1', 'nome' => 'Turma 1'])->assertForbidden();
        $this->post(route('turnos.store'), ['nome' => 'Manhã'])->assertForbidden();
        $this->post(route('niveis-academicos.store'), ['codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1])->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('turmas.index'))->assertForbidden();
        $this->get(route('turnos.index'))->assertForbidden();
        $this->get(route('niveis-academicos.index'))->assertForbidden();
    }
}
