<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Tests\TestCase;

class FrequenciaPermissoesTest extends TestCase
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
        $moduloId = Modulo::where('nome', ModuloEnum::FREQUENCIA->value)->value('id');

        $acoes = RolePermissao::query()
            ->where('role_id', Role::where('nome', $perfil->value)->value('id'))
            ->where('modulo_id', $moduloId)
            ->with('acao')
            ->get()
            ->map(fn (RolePermissao $p) => $p->acao->nome)
            ->all();
        sort($acoes);

        return $acoes;
    }

    private function ordenado(array $acoes): array
    {
        sort($acoes);

        return $acoes;
    }

    public function test_modulo_tem_slug_e_accoes(): void
    {
        $this->assertSame(33, ModuloEnum::FREQUENCIA->value);
        $this->assertSame('frequencia', ModuloEnum::FREQUENCIA->slug());
        $this->assertSame('Frequência', ModuloEnum::FREQUENCIA->label());
        $this->assertSame(
            ['ver', 'listar', 'criar', 'editar', 'justificar', 'corrigir-fora-prazo', 'configurar', 'ver-proprias'],
            ModuloEnum::FREQUENCIA->acoesAplicaveis(),
        );
    }

    public function test_administrador_tem_as_accoes_de_gestao_e_nao_ver_proprias(): void
    {
        $this->assertSame(
            $this->ordenado(['ver', 'listar', 'criar', 'editar', 'justificar', 'corrigir-fora-prazo', 'configurar']),
            $this->acoesDoPerfil(Perfil::ADMIN_ESCOLA),
        );
    }

    public function test_funcionario_abre_corrige_e_justifica_mas_nao_configura(): void
    {
        $this->assertSame($this->ordenado(['ver', 'listar', 'criar', 'editar', 'justificar', 'corrigir-fora-prazo']), $this->acoesDoPerfil(Perfil::FUNCIONARIO));
    }

    public function test_professor_marca_e_justifica_mas_nao_corrige_fora_do_prazo(): void
    {
        $this->assertSame($this->ordenado(['ver', 'criar', 'editar', 'justificar']), $this->acoesDoPerfil(Perfil::PROFESSOR));
    }

    public function test_aluno_so_tem_ver_proprias(): void
    {
        $this->assertSame(['ver-proprias'], $this->acoesDoPerfil(Perfil::ALUNO));
    }

    public function test_encarregado_sem_acesso(): void
    {
        $this->assertSame([], $this->acoesDoPerfil(Perfil::ENCARREGADO));
    }
}
