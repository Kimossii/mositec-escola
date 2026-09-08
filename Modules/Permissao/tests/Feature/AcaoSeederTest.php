<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Tests\TestCase;

class AcaoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_inclui_a_acao_exportar(): void
    {
        $this->seed(AcaoSeeder::class);

        $this->assertDatabaseHas('acoes', ['nome' => 'exportar', 'numero' => 5]);
    }

    public function test_seeder_e_idempotente(): void
    {
        $this->seed(AcaoSeeder::class);
        $contagemInicial = Acao::count();

        $this->seed(AcaoSeeder::class);

        $this->assertSame($contagemInicial, Acao::count());
        $this->assertSame(6, Acao::count());
    }

    public function test_seeder_nao_apaga_role_permissoes_dependentes(): void
    {
        $this->seed(ModuloSeeder::class);
        $this->seed(AcaoSeeder::class);

        $modulo = Modulo::where('nome', 6)->first();
        $acao = Acao::where('nome', 'criar')->first();

        $role = Role::create(['nome' => 99, 'descricao' => 'Teste']);
        $rolePermissao = RolePermissao::create([
            'role_id' => $role->id,
            'modulo_id' => $modulo->id,
            'acao_id' => $acao->id,
        ]);

        $this->seed(AcaoSeeder::class);

        $this->assertDatabaseHas('role_permissoes', ['id' => $rolePermissao->id]);
        $this->assertNotNull(RolePermissao::find($rolePermissao->id));
    }
}
