<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Cobre o risco identificado na revisão da Task 13: o Controller usa route-model-binding
 * simples (sem global scope por estabelecimento). Estes testes garantem que um utilizador
 * autenticado no estabelecimento A não consegue ver, alterar ou mutar dados de um
 * PlanoCurricular do estabelecimento B só porque adivinha/itera o id.
 */
class PlanoCurricularIsolamentoTest extends TestCase
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

    private function criarEstabelecimento(bool $activo): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => $activo]);
    }

    private function criarCurso(Estabelecimento $estabelecimento, string $codigo, ?string $nome = null): Curso
    {
        return Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => $codigo, 'nome' => $nome ?? "Curso {$codigo}"]);
    }

    private function criarDisciplina(Estabelecimento $estabelecimento, string $codigo): Disciplina
    {
        return Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => $codigo, 'nome' => 'Matemática']);
    }

    private function criarNivelAcademico(Estabelecimento $estabelecimento, string $codigo = '1C'): NivelAcademico
    {
        return NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => $codigo, 'nome' => '1ª Classe', 'ordem' => 1]);
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

    private function criarPlano(Estabelecimento $estabelecimento, Curso $curso, string $codigo): PlanoCurricular
    {
        return PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => $codigo,
            'nome' => 'Plano do Estabelecimento B',
        ]);
    }

    /**
     * Prepara o cenário base: estabelecimento A activo (o "actual"), estabelecimento B
     * inactivo com um plano curricular seu. O staff autentica-se no contexto de A.
     *
     * @return array{0: Estabelecimento, 1: Estabelecimento, 2: PlanoCurricular}
     */
    private function prepararCenarioCrossEstabelecimento(): array
    {
        $this->actingAsStaff();

        $estabelecimentoA = $this->criarEstabelecimento(true);
        $estabelecimentoB = $this->criarEstabelecimento(false);

        $cursoB = $this->criarCurso($estabelecimentoB, 'CONT');
        $planoB = $this->criarPlano($estabelecimentoB, $cursoB, 'PLC');

        return [$estabelecimentoA, $estabelecimentoB, $planoB];
    }

    public function test_show_de_plano_de_outro_estabelecimento_devolve_404(): void
    {
        [, , $planoB] = $this->prepararCenarioCrossEstabelecimento();

        $this->get(route('planos-curriculares.show', $planoB))->assertNotFound();
    }

    public function test_update_de_plano_de_outro_estabelecimento_devolve_404_e_nao_muta_dados(): void
    {
        [$estabelecimentoA, , $planoB] = $this->prepararCenarioCrossEstabelecimento();
        $cursoA = $this->criarCurso($estabelecimentoA, 'INF');

        $this->put(route('planos-curriculares.update', $planoB), [
            'curso_id' => $cursoA->id,
            'codigo' => 'PLC',
            'nome' => 'Tentativa de sequestro do plano',
        ])->assertNotFound();

        $planoB->refresh();
        $this->assertSame('Plano do Estabelecimento B', $planoB->nome);
        $this->assertNotSame($cursoA->id, $planoB->curso_id);
    }

    public function test_alterar_estado_de_plano_de_outro_estabelecimento_devolve_404(): void
    {
        [, , $planoB] = $this->prepararCenarioCrossEstabelecimento();

        $this->patch(route('planos-curriculares.alterar-estado', $planoB), [
            'estado' => Estado::INATIVO->value,
        ])->assertNotFound();

        $this->assertSame(Estado::ATIVO->value, $planoB->fresh()->estado);
    }

    public function test_disciplinas_store_em_plano_de_outro_estabelecimento_devolve_404(): void
    {
        [$estabelecimentoA, , $planoB] = $this->prepararCenarioCrossEstabelecimento();
        $disciplinaA = $this->criarDisciplina($estabelecimentoA, 'MAT');
        $nivelA = $this->criarNivelAcademico($estabelecimentoA);

        $this->post(route('planos-curriculares.disciplinas.store', $planoB), [
            'disciplina_id' => $disciplinaA->id,
            'nivel_academico_id' => $nivelA->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ])->assertNotFound();

        $this->assertDatabaseMissing('plano_curricular_disciplinas', ['plano_curricular_id' => $planoB->id]);
    }

    public function test_disciplinas_update_em_plano_de_outro_estabelecimento_devolve_404(): void
    {
        [$estabelecimentoA, $estabelecimentoB, $planoB] = $this->prepararCenarioCrossEstabelecimento();
        $disciplinaB = $this->criarDisciplina($estabelecimentoB, 'MAT');
        $nivelB = $this->criarNivelAcademico($estabelecimentoB);
        $itemB = $planoB->disciplinas()->create([
            'disciplina_id' => $disciplinaB->id,
            'nivel_academico_id' => $nivelB->id,
            'tipo' => TipoDisciplinaPlano::NORMAL,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        // Payload válido para o estabelecimento actual (A), para isolar exactamente
        // a guarda do Controller — não uma rejeição da FormRequest por FK inválida.
        $disciplinaA = $this->criarDisciplina($estabelecimentoA, 'MAT-A');
        $nivelA = $this->criarNivelAcademico($estabelecimentoA);

        $this->put(route('planos-curriculares.disciplinas.update', [$planoB, $itemB]), [
            'disciplina_id' => $disciplinaA->id,
            'nivel_academico_id' => $nivelA->id,
            'tipo' => TipoDisciplinaPlano::OPTATIVA->value,
            'obrigatoria' => false,
            'ordem' => 9,
        ])->assertNotFound();

        $itemB->refresh();
        $this->assertSame(TipoDisciplinaPlano::NORMAL, $itemB->tipo);
        $this->assertTrue($itemB->obrigatoria);
    }

    public function test_disciplinas_destroy_em_plano_de_outro_estabelecimento_devolve_404(): void
    {
        [, $estabelecimentoB, $planoB] = $this->prepararCenarioCrossEstabelecimento();
        $disciplinaB = $this->criarDisciplina($estabelecimentoB, 'MAT');
        $nivelB = $this->criarNivelAcademico($estabelecimentoB);
        $itemB = $planoB->disciplinas()->create([
            'disciplina_id' => $disciplinaB->id,
            'nivel_academico_id' => $nivelB->id,
            'tipo' => TipoDisciplinaPlano::NORMAL,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        $this->delete(route('planos-curriculares.disciplinas.destroy', [$planoB, $itemB]))->assertNotFound();

        $this->assertDatabaseHas('plano_curricular_disciplinas', ['id' => $itemB->id]);
    }

    public function test_anos_lectivos_store_em_plano_de_outro_estabelecimento_devolve_404(): void
    {
        [$estabelecimentoA, , $planoB] = $this->prepararCenarioCrossEstabelecimento();
        $anoLectivoA = $this->criarAnoLectivo($estabelecimentoA);

        $this->post(route('planos-curriculares.anos-lectivos.store', $planoB), [
            'ano_lectivo_id' => $anoLectivoA->id,
        ])->assertNotFound();

        $this->assertDatabaseMissing('plano_curricular_anos_lectivos', ['plano_curricular_id' => $planoB->id]);
    }

    public function test_definir_periodos_disciplina_em_plano_de_outro_estabelecimento_devolve_404(): void
    {
        [, $estabelecimentoB, $planoB] = $this->prepararCenarioCrossEstabelecimento();
        $disciplinaB = $this->criarDisciplina($estabelecimentoB, 'MAT');
        $nivelB = $this->criarNivelAcademico($estabelecimentoB);
        $itemB = $planoB->disciplinas()->create([
            'disciplina_id' => $disciplinaB->id,
            'nivel_academico_id' => $nivelB->id,
            'tipo' => TipoDisciplinaPlano::NORMAL,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);
        $anoLectivoB = $this->criarAnoLectivo($estabelecimentoB);
        $periodoB = Periodo::create(['ano_lectivo_id' => $anoLectivoB->id, 'nome' => '1º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-04-01']);
        $aplicacaoB = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoB->id, 'ano_lectivo_id' => $anoLectivoB->id]);

        $this->put(route('planos-curriculares.anos-lectivos.disciplinas.periodos.update', [$planoB, $aplicacaoB, $itemB]), [
            'periodo_ids' => [$periodoB->id],
        ])->assertNotFound();

        $this->assertDatabaseMissing('plano_curricular_disciplina_periodos', ['plano_curricular_disciplina_id' => $itemB->id]);
    }

    public function test_store_rejeita_curso_de_outro_estabelecimento(): void
    {
        [, $estabelecimentoB] = $this->prepararCenarioCrossEstabelecimento();
        $cursoB = $this->criarCurso($estabelecimentoB, 'CONT2');

        $this->post(route('planos-curriculares.store'), [
            'curso_id' => $cursoB->id,
            'codigo' => 'PLX',
            'nome' => 'Plano inválido',
        ])->assertSessionHasErrors('curso_id');

        $this->assertDatabaseMissing('planos_curriculares', ['codigo' => 'PLX']);
    }

    public function test_disciplinas_store_rejeita_disciplina_de_outro_estabelecimento(): void
    {
        [$estabelecimentoA, $estabelecimentoB] = $this->prepararCenarioCrossEstabelecimento();
        $cursoA = $this->criarCurso($estabelecimentoA, 'INF');
        $planoA = $this->criarPlano($estabelecimentoA, $cursoA, 'PLI');
        $disciplinaB = $this->criarDisciplina($estabelecimentoB, 'MAT');
        $nivelB = $this->criarNivelAcademico($estabelecimentoB);

        $this->post(route('planos-curriculares.disciplinas.store', $planoA), [
            'disciplina_id' => $disciplinaB->id,
            'nivel_academico_id' => $nivelB->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ])->assertSessionHasErrors(['disciplina_id', 'nivel_academico_id']);
    }

    public function test_anos_lectivos_store_rejeita_ano_lectivo_de_outro_estabelecimento(): void
    {
        [$estabelecimentoA, $estabelecimentoB] = $this->prepararCenarioCrossEstabelecimento();
        $cursoA = $this->criarCurso($estabelecimentoA, 'INF');
        $planoA = $this->criarPlano($estabelecimentoA, $cursoA, 'PLI');
        $anoLectivoB = $this->criarAnoLectivo($estabelecimentoB);

        $this->post(route('planos-curriculares.anos-lectivos.store', $planoA), [
            'ano_lectivo_id' => $anoLectivoB->id,
        ])->assertSessionHasErrors('ano_lectivo_id');
    }
}
