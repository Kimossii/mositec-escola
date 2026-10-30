<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** Escola só de Creche, Pré-Escolar ou Primário não tem cursos: a rota some e o frontend recebe `usa_cursos = false`. */
class CursoEscolaSemCursosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('segredo123')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);
    }

    private function declararEtapas(EtapaEnsinoEnum ...$etapas): void
    {
        foreach ($etapas as $etapa) {
            EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => Estabelecimento::current()->id, 'etapa_ensino' => $etapa]);
        }
    }

    public function test_escola_so_com_etapas_sem_curso_nao_tem_rota_de_cursos(): void
    {
        $this->declararEtapas(EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::PRE_ESCOLAR, EtapaEnsinoEnum::PRIMARIO);

        $this->get('/cursos')->assertNotFound();
        $this->get('/turmas')->assertInertia(fn (Assert $p) => $p->where('usa_cursos', false));
    }

    public function test_basta_uma_etapa_que_exija_curso(): void
    {
        $this->declararEtapas(EtapaEnsinoEnum::PRIMARIO, EtapaEnsinoEnum::SECUNDARIO);

        $this->get('/cursos')->assertOk();
        $this->get('/turmas')->assertInertia(fn (Assert $p) => $p->where('usa_cursos', true));
    }

    public function test_escola_sem_etapas_declaradas_nao_esconde_nada(): void
    {
        $this->get('/cursos')->assertOk();
        $this->get('/turmas')->assertInertia(fn (Assert $p) => $p->where('usa_cursos', true));
    }
}
