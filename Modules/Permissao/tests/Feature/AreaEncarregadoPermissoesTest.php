<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Tests\TestCase;

class AreaEncarregadoPermissoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    /** @return list<string> */
    private function acoesDoPerfil(Perfil $perfil): array
    {
        $moduloId = Modulo::where('nome', ModuloEnum::AREA_ENCARREGADO->value)->value('id');

        return RolePermissao::query()
            ->where('role_id', Role::where('nome', $perfil->value)->value('id'))
            ->where('modulo_id', $moduloId)
            ->with('acao')
            ->get()
            ->map(fn (RolePermissao $p) => $p->acao->nome)
            ->all();
    }

    public function test_modulo_tem_slug_e_accoes(): void
    {
        $this->assertSame(35, ModuloEnum::AREA_ENCARREGADO->value);
        $this->assertSame('area-encarregado', ModuloEnum::AREA_ENCARREGADO->slug());
        $this->assertSame('Área do Encarregado', ModuloEnum::AREA_ENCARREGADO->label());
        $this->assertSame(['ver'], ModuloEnum::AREA_ENCARREGADO->acoesAplicaveis());
    }

    public function test_encarregado_so_tem_ver_na_area_do_encarregado(): void
    {
        $this->assertSame(['ver'], $this->acoesDoPerfil(Perfil::ENCARREGADO));
    }

    public function test_nenhum_outro_perfil_tem_a_area_do_encarregado(): void
    {
        foreach ([Perfil::ADMIN_ESCOLA, Perfil::FUNCIONARIO, Perfil::PROFESSOR, Perfil::ALUNO] as $perfil) {
            $this->assertSame([], $this->acoesDoPerfil($perfil), $perfil->name);
        }
    }

    public function test_encarregado_so_tem_essa_permissao_em_todo_o_sistema(): void
    {
        $this->assertSame(1, RolePermissao::where('role_id', Role::where('nome', Perfil::ENCARREGADO->value)->value('id'))->count());
    }
}
