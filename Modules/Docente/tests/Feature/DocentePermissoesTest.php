<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Tests\TestCase;

class DocentePermissoesTest extends TestCase
{
    use RefreshDatabase;

    private const TODAS = ['ver', 'criar', 'editar', 'eliminar', 'listar', 'exportar'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    /** @return list<string> */
    private function acoesDoPerfil(Perfil $perfil): array
    {
        $moduloId = Modulo::where('nome', ModuloEnum::DOCENTE->value)->value('id');

        return RolePermissao::query()
            ->where('role_id', Role::where('nome', $perfil->value)->value('id'))
            ->where('modulo_id', $moduloId)
            ->with('acao')
            ->get()
            ->map(fn (RolePermissao $p) => $p->acao->nome)
            ->sort()
            ->values()
            ->all();
    }

    private function ordenado(array $acoes): array
    {
        sort($acoes);

        return $acoes;
    }

    public function test_modulo_tem_identidade_e_acoes_aplicaveis(): void
    {
        $this->assertSame(29, ModuloEnum::DOCENTE->value);
        $this->assertSame('docente', ModuloEnum::DOCENTE->slug());
        $this->assertSame('Docente', ModuloEnum::DOCENTE->label());
        $this->assertSame(self::TODAS, ModuloEnum::DOCENTE->acoesAplicaveis());
    }

    public function test_admin_escola_tem_todas_as_acoes(): void
    {
        $this->assertSame($this->ordenado(self::TODAS), $this->acoesDoPerfil(Perfil::ADMIN_ESCOLA));
    }

    public function test_funcionario_tem_ver_listar_criar_editar_mas_nao_eliminar_nem_exportar(): void
    {
        $this->assertSame(
            $this->ordenado(['ver', 'listar', 'criar', 'editar']),
            $this->acoesDoPerfil(Perfil::FUNCIONARIO),
        );
    }

    public function test_professor_aluno_e_encarregado_nao_tem_acesso(): void
    {
        foreach ([Perfil::PROFESSOR, Perfil::ALUNO, Perfil::ENCARREGADO] as $perfil) {
            $this->assertSame([], $this->acoesDoPerfil($perfil), $perfil->name);
        }
    }
}
