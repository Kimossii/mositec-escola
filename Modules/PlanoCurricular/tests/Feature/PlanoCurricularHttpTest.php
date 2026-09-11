<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PlanoCurricularHttpTest extends TestCase
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

    private function criarCurso(Estabelecimento $estabelecimento, string $codigo = 'INF'): Curso
    {
        return Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => $codigo, 'nome' => 'Informática']);
    }

    private function criarDisciplina(Estabelecimento $estabelecimento, string $codigo = 'MAT'): Disciplina
    {
        return Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => $codigo, 'nome' => 'Matemática']);
    }

    private function criarNivelAcademico(Estabelecimento $estabelecimento, string $codigo = '1C'): NivelAcademico
    {
        return NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => $codigo, 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
    }

    private function criarAnoLectivo(Estabelecimento $estabelecimento, string $nome = '2026'): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => $nome,
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
    }

    private function criarPlano(Estabelecimento $estabelecimento, Curso $curso, string $codigo = 'PLI'): PlanoCurricular
    {
        return PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => $codigo,
            'nome' => 'Plano Informática',
        ]);
    }

    public function test_store_cria_plano_associado_ao_curso(): void
    {
        $staff = $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);

        $this->post(route('planos-curriculares.store'), [
            'curso_id' => $curso->id,
            'codigo' => 'PLI',
            'nome' => 'Plano Informática',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $plano = PlanoCurricular::firstWhere('codigo', 'PLI');
        $this->assertNotNull($plano);
        $this->assertSame($curso->id, $plano->curso_id);
        $this->assertSame($estabelecimento->id, $plano->estabelecimento_id);
        $this->assertSame($staff->id, $plano->criado_por);
        $this->assertSame('Ativo', $plano->estado_descricao);
    }

    public function test_show_expoe_o_plano(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);

        $this->get(route('planos-curriculares.show', $plano))->assertInertia(fn (Assert $page) => $page
            ->component('PlanoCurricular/Show')
            ->where('planoCurricular.codigo', 'PLI')
        );
    }

    public function test_atualiza_plano_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);

        $this->put(route('planos-curriculares.update', $plano), [
            'curso_id' => $curso->id,
            'codigo' => 'PLI',
            'nome' => 'Plano Informática Renovado',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Plano Informática Renovado', $plano->fresh()->nome);
    }

    public function test_altera_estado_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);

        $this->patch(route('planos-curriculares.alterar-estado', $plano), [
            'estado' => Estado::INATIVO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $plano->refresh();
        $this->assertSame(Estado::INATIVO->value, $plano->estado);
        $this->assertSame('Inativo', $plano->estado_descricao);
    }

    public function test_disciplinas_store_associa_disciplina_ao_plano(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);
        $disciplina = $this->criarDisciplina($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);

        $this->post(route('planos-curriculares.disciplinas.store', $plano), [
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('plano_curricular_disciplinas', [
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
        ]);
    }

    public function test_disciplinas_store_rejeita_duplicar_mesma_disciplina_no_mesmo_nivel(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);
        $disciplina = $this->criarDisciplina($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);

        $plano->disciplinas()->create([
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::NORMAL,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        $this->post(route('planos-curriculares.disciplinas.store', $plano), [
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 2,
        ])->assertSessionHasErrors('disciplina_id');
    }

    public function test_disciplinas_update_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);
        $disciplina = $this->criarDisciplina($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $item = $plano->disciplinas()->create([
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::NORMAL,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        $this->put(route('planos-curriculares.disciplinas.update', [$plano, $item]), [
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::OPTATIVA->value,
            'obrigatoria' => false,
            'ordem' => 3,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $item->refresh();
        $this->assertSame(TipoDisciplinaPlano::OPTATIVA, $item->tipo);
        $this->assertFalse($item->obrigatoria);
        $this->assertSame(3, $item->ordem);
    }

    public function test_disciplinas_destroy_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);
        $disciplina = $this->criarDisciplina($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $item = $plano->disciplinas()->create([
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::NORMAL,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        $this->delete(route('planos-curriculares.disciplinas.destroy', [$plano, $item]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseMissing('plano_curricular_disciplinas', ['id' => $item->id]);
    }

    public function test_anos_lectivos_store_confirma_plano_para_ano_lectivo(): void
    {
        $staff = $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);

        $this->post(route('planos-curriculares.anos-lectivos.store', $plano), [
            'ano_lectivo_id' => $anoLectivo->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('plano_curricular_anos_lectivos', [
            'plano_curricular_id' => $plano->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'confirmado_por' => $staff->id,
        ]);
    }

    public function test_anos_lectivos_store_permite_reconfirmar_plano_em_ano_diferente(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);
        $anoLectivoA = $this->criarAnoLectivo($estabelecimento, '2026');
        $anoLectivoB = $this->criarAnoLectivo($estabelecimento, '2027');

        $this->post(route('planos-curriculares.anos-lectivos.store', $plano), [
            'ano_lectivo_id' => $anoLectivoA->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->post(route('planos-curriculares.anos-lectivos.store', $plano), [
            'ano_lectivo_id' => $anoLectivoB->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('plano_curricular_anos_lectivos', ['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivoA->id]);
        $this->assertDatabaseHas('plano_curricular_anos_lectivos', ['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivoB->id]);
    }

    public function test_definir_periodos_disciplina_associa_varios_periodos_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);
        $disciplina = $this->criarDisciplina($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $item = $plano->disciplinas()->create(['disciplina_id' => $disciplina->id, 'nivel_academico_id' => $nivel->id, 'tipo' => TipoDisciplinaPlano::NORMAL, 'obrigatoria' => true, 'ordem' => 1]);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $periodo1 = Periodo::create(['ano_lectivo_id' => $anoLectivo->id, 'nome' => '1º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-04-01']);
        $periodo2 = Periodo::create(['ano_lectivo_id' => $anoLectivo->id, 'nome' => '2º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 2, 'data_inicio' => '2026-04-02', 'data_fim' => '2026-08-01']);
        $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);

        $this->put(route('planos-curriculares.anos-lectivos.disciplinas.periodos.update', [$plano, $aplicacao, $item]), [
            'periodo_ids' => [$periodo1->id, $periodo2->id],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('plano_curricular_disciplina_periodos', ['plano_curricular_ano_lectivo_id' => $aplicacao->id, 'plano_curricular_disciplina_id' => $item->id, 'periodo_id' => $periodo1->id]);
        $this->assertDatabaseHas('plano_curricular_disciplina_periodos', ['plano_curricular_ano_lectivo_id' => $aplicacao->id, 'plano_curricular_disciplina_id' => $item->id, 'periodo_id' => $periodo2->id]);
    }

    public function test_definir_periodos_disciplina_rejeita_periodo_de_outro_ano_lectivo_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);
        $disciplina = $this->criarDisciplina($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $item = $plano->disciplinas()->create(['disciplina_id' => $disciplina->id, 'nivel_academico_id' => $nivel->id, 'tipo' => TipoDisciplinaPlano::NORMAL, 'obrigatoria' => true, 'ordem' => 1]);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento, '2026');
        $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);
        $outroAnoLectivo = $this->criarAnoLectivo($estabelecimento, '2027');
        $periodoDeOutroAno = Periodo::create(['ano_lectivo_id' => $outroAnoLectivo->id, 'nome' => '1º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2027-01-01', 'data_fim' => '2027-04-01']);

        $this->put(route('planos-curriculares.anos-lectivos.disciplinas.periodos.update', [$plano, $aplicacao, $item]), [
            'periodo_ids' => [$periodoDeOutroAno->id],
        ])->assertSessionHasErrors('periodo_ids.0');

        $this->assertDatabaseMissing('plano_curricular_disciplina_periodos', ['plano_curricular_disciplina_id' => $item->id]);
    }

    public function test_definir_periodos_disciplina_rejeita_disciplina_que_nao_pertence_ao_plano_da_aplicacao(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);
        $outroPlano = $this->criarPlano($estabelecimento, $curso, 'PLO');
        $disciplina = $this->criarDisciplina($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $itemDeOutroPlano = $outroPlano->disciplinas()->create(['disciplina_id' => $disciplina->id, 'nivel_academico_id' => $nivel->id, 'tipo' => TipoDisciplinaPlano::NORMAL, 'obrigatoria' => true, 'ordem' => 1]);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);

        $this->put(route('planos-curriculares.anos-lectivos.disciplinas.periodos.update', [$plano, $aplicacao, $itemDeOutroPlano]), [
            'periodo_ids' => [],
        ])->assertNotFound();
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $plano = $this->criarPlano($estabelecimento, $curso);

        $this->post(route('planos-curriculares.store'), ['curso_id' => $curso->id, 'codigo' => 'PLX', 'nome' => 'Outro Plano'])->assertForbidden();
        $this->put(route('planos-curriculares.update', $plano), ['curso_id' => $curso->id, 'codigo' => 'PLI', 'nome' => 'Plano Renovado'])->assertForbidden();
        $this->patch(route('planos-curriculares.alterar-estado', $plano), ['estado' => Estado::INATIVO->value])->assertForbidden();
    }
}
