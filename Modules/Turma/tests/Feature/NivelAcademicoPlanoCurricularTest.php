<?php

namespace Modules\Turma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
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
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    private function criarNivelAcademico(Estabelecimento $estabelecimento): NivelAcademico
    {
        return NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
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
}
