<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Cobre a "regra histórica fundamental" do módulo: um novo plano curricular
 * (versão) para um ano lectivo posterior nunca deve alterar retroactivamente
 * um plano já confirmado em anos lectivos anteriores. Cada plano é uma
 * fotografia imutável do currículo nos anos lectivos em que foi confirmado.
 */
class PlanoCurricularHistoricoTest extends TestCase
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

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    private function criarAnoLectivo(Estabelecimento $estabelecimento, string $nome): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => $nome,
            'data_inicio' => "{$nome}-01-01",
            'data_fim' => "{$nome}-12-31",
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
    }

    public function test_novo_plano_confirmado_para_ano_lectivo_posterior_nao_altera_plano_anterior(): void
    {
        $staff = $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT', 'nome' => 'Matemática']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1]);

        $anoLectivo2026 = $this->criarAnoLectivo($estabelecimento, '2026');
        $anoLectivo2027 = $this->criarAnoLectivo($estabelecimento, '2027');
        $anoLectivo2028 = $this->criarAnoLectivo($estabelecimento, '2028');

        // --- Plano A: criado, com disciplina, confirmado para 2026 e 2027 ---
        $this->post(route('planos-curriculares.store'), [
            'curso_id' => $curso->id,
            'codigo' => 'PLA',
            'nome' => 'Plano Informática v1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $planoA = PlanoCurricular::firstWhere('codigo', 'PLA');

        $this->post(route('planos-curriculares.disciplinas.store', $planoA), [
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->post(route('planos-curriculares.anos-lectivos.store', $planoA), [
            'ano_lectivo_id' => $anoLectivo2026->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->post(route('planos-curriculares.anos-lectivos.store', $planoA), [
            'ano_lectivo_id' => $anoLectivo2027->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Fotografia do estado do Plano A antes de o Plano B existir.
        $planoA->refresh()->load('disciplinas', 'anosLectivos');
        $nomeOriginalA = $planoA->nome;
        $disciplinasOriginaisA = $planoA->disciplinas->pluck('id')->sort()->values()->all();
        $confirmacoesOriginaisA = $planoA->anosLectivos()
            ->get(['ano_lectivo_id', 'confirmado_por', 'confirmado_em'])
            ->map(fn ($c) => $c->only(['ano_lectivo_id', 'confirmado_por']))
            ->toArray();

        $this->assertCount(2, $confirmacoesOriginaisA);

        // --- Plano B: nova versão, criado e confirmado para 2028 ---
        $this->post(route('planos-curriculares.store'), [
            'curso_id' => $curso->id,
            'codigo' => 'PLB',
            'nome' => 'Plano Informática v2',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $planoB = PlanoCurricular::firstWhere('codigo', 'PLB');

        $this->post(route('planos-curriculares.disciplinas.store', $planoB), [
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->post(route('planos-curriculares.anos-lectivos.store', $planoB), [
            'ano_lectivo_id' => $anoLectivo2028->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Editar o Plano B (nome + disciplinas) não deve tocar no Plano A.
        $this->put(route('planos-curriculares.update', $planoB), [
            'curso_id' => $curso->id,
            'codigo' => 'PLB',
            'nome' => 'Plano Informática v2 (revisto)',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $itemB = $planoB->disciplinas()->first();
        $this->put(route('planos-curriculares.disciplinas.update', [$planoB, $itemB]), [
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::OPTATIVA->value,
            'obrigatoria' => false,
            'ordem' => 2,
        ])->assertSessionHasNoErrors()->assertRedirect();

        // --- Asserts: Plano A permanece completamente inalterado ---
        $planoA->refresh()->load('disciplinas', 'anosLectivos');

        $this->assertSame($nomeOriginalA, $planoA->nome);
        $this->assertSame('PLA', $planoA->codigo);
        $this->assertSame($disciplinasOriginaisA, $planoA->disciplinas->pluck('id')->sort()->values()->all());

        $confirmacoesActuaisA = $planoA->anosLectivos()
            ->get(['ano_lectivo_id', 'confirmado_por', 'confirmado_em'])
            ->map(fn ($c) => $c->only(['ano_lectivo_id', 'confirmado_por']))
            ->toArray();
        $this->assertSame($confirmacoesOriginaisA, $confirmacoesActuaisA);

        $this->assertDatabaseHas('plano_curricular_anos_lectivos', [
            'plano_curricular_id' => $planoA->id,
            'ano_lectivo_id' => $anoLectivo2026->id,
            'confirmado_por' => $staff->id,
        ]);
        $this->assertDatabaseHas('plano_curricular_anos_lectivos', [
            'plano_curricular_id' => $planoA->id,
            'ano_lectivo_id' => $anoLectivo2027->id,
            'confirmado_por' => $staff->id,
        ]);

        // A disciplina do Plano A continua com os dados originais (não foi
        // afectada pela edição da disciplina homónima do Plano B).
        $itemA = $planoA->disciplinas->first();
        $this->assertSame(TipoDisciplinaPlano::NORMAL, $itemA->tipo);
        $this->assertTrue($itemA->obrigatoria);
        $this->assertSame(1, $itemA->ordem);

        // --- Plano B ficou correctamente editado, isolado do A ---
        $planoB->refresh()->load('disciplinas', 'anosLectivos');
        $this->assertSame('Plano Informática v2 (revisto)', $planoB->nome);
        $this->assertDatabaseHas('plano_curricular_anos_lectivos', [
            'plano_curricular_id' => $planoB->id,
            'ano_lectivo_id' => $anoLectivo2028->id,
        ]);
        $itemBAtual = $planoB->disciplinas->first();
        $this->assertSame(TipoDisciplinaPlano::OPTATIVA, $itemBAtual->tipo);
        $this->assertFalse($itemBAtual->obrigatoria);

        // Os dois planos coexistem como registos independentes.
        $this->assertNotSame($planoA->id, $planoB->id);
        $this->assertSame(2, PlanoCurricular::count());
    }
}
