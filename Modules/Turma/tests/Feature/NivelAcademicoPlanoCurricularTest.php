<?php

namespace Modules\Turma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Cobre o fluxo, introduzido por esta feature, de criar um PlanoCurricular a
 * partir de um Nível Académico sem passar por nenhum Curso (curso_id opcional).
 */
class NivelAcademicoPlanoCurricularTest extends TestCase
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
        return $this->estabelecimentoDeTeste(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value]);
    }

    private function criarNivelAcademico(Estabelecimento $estabelecimento): NivelAcademico
    {
        return NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 3]);
    }

    public function test_cria_plano_curricular_a_partir_do_nivel_academico_sem_curso_e_aparece_no_nivel(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $nivel = $this->criarNivelAcademico($estabelecimento);

        $this->post(route('planos-curriculares.store'), [
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'PLN',
            'nome' => 'Plano do Nível',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $plano = PlanoCurricular::firstWhere('codigo', 'PLN');
        $this->assertNotNull($plano);
        $this->assertNull($plano->curso_id);
        $this->assertSame($nivel->id, $plano->nivel_academico_id);

        $this->get(route('niveis-academicos.show', $nivel))->assertInertia(fn (Assert $page) => $page
            ->component('Turma/NiveisAcademicos/Show')
            ->has('nivelAcademico.planos_curriculares', 1)
            ->where('nivelAcademico.planos_curriculares.0.codigo', 'PLN')
        );
    }

    public function test_7_a_9_classe_aceita_plano_directo_e_10_classe_em_diante_exige_curso(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $nona = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'SEC_9', 'nome' => '9.ª Classe', 'ordem' => 14, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        $decima = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'SEC_10', 'nome' => '10.ª Classe', 'ordem' => 15, 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO]);

        $this->post(route('planos-curriculares.store'), ['nivel_academico_id' => $nona->id, 'codigo' => 'P9', 'nome' => 'Plano 9'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull(PlanoCurricular::firstWhere('codigo', 'P9'));

        $this->post(route('planos-curriculares.store'), ['nivel_academico_id' => $decima->id, 'codigo' => 'P10', 'nome' => 'Plano 10'])
            ->assertSessionHasErrors('curso_id');
    }
}
