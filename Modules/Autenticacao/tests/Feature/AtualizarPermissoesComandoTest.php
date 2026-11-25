<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Tests\TestCase;

class AtualizarPermissoesComandoTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_comando_repoe_tambem_os_modulos_em_falta_no_catalogo(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);

        // Como numa escola anterior ao módulo: sem o módulo no catálogo e sem a permissão no perfil.
        $modulo = Modulo::where('nome', ModuloEnum::AREA_ENCARREGADO->value)->first();
        RolePermissao::where('modulo_id', $modulo->id)->delete();
        $modulo->delete();

        $this->artisan('mosi:permissoes:atualizar', ['--tenant' => $this->tenant->codigo])->assertExitCode(0);

        $moduloId = Modulo::where('nome', ModuloEnum::AREA_ENCARREGADO->value)->value('id');
        $this->assertNotNull($moduloId);
        $this->assertSame(1, RolePermissao::where('modulo_id', $moduloId)->where('role_id', Role::where('nome', Perfil::ENCARREGADO->value)->value('id'))->count());
    }
}
