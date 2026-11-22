<?php

namespace Modules\Horario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Tests\TestCase;

class HorarioPermissoesTest extends TestCase
{
    use RefreshDatabase;

    private const TODAS = ['criar', 'editar', 'eliminar', 'encerrar', 'listar', 'ver'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    /** @return list<string> */
    private function acoesDoPerfil(Perfil $perfil): array
    {
        $moduloId = Modulo::where('nome', ModuloEnum::GRELHA_HORARIA->value)->value('id');

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

    public function test_modulo_grelha_horaria_tem_slug_e_accoes(): void
    {
        $this->assertSame(32, ModuloEnum::GRELHA_HORARIA->value);
        $this->assertSame('grelha-horaria', ModuloEnum::GRELHA_HORARIA->slug());
        $this->assertSame('Grelha de Horários', ModuloEnum::GRELHA_HORARIA->label());
        $this->assertSame(['ver', 'listar', 'criar', 'editar', 'encerrar', 'eliminar'], ModuloEnum::GRELHA_HORARIA->acoesAplicaveis());
    }

    public function test_administrador_tem_todas_as_accoes(): void
    {
        $this->assertSame(self::TODAS, $this->acoesDoPerfil(Perfil::ADMIN_ESCOLA));
    }

    public function test_funcionario_nao_tem_eliminar(): void
    {
        $this->assertSame(['criar', 'editar', 'encerrar', 'listar', 'ver'], $this->acoesDoPerfil(Perfil::FUNCIONARIO));
    }

    public function test_professor_aluno_e_encarregado_nao_tem_acesso(): void
    {
        foreach ([Perfil::PROFESSOR, Perfil::ALUNO, Perfil::ENCARREGADO] as $perfil) {
            $this->assertSame([], $this->acoesDoPerfil($perfil), $perfil->name);
        }
    }
}
