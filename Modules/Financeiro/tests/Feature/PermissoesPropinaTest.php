<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesPropinaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizadorCom(Perfil $perfil): User
    {
        $user = User::create(['name' => 'Teste', 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);

        return $user;
    }

    public function test_modulo_tem_slug_label_e_acoes_proprias(): void
    {
        $this->assertSame(22, Modulo::PROPINA->value);
        $this->assertSame('propina', Modulo::PROPINA->slug());
        $this->assertSame(Modulo::PROPINA, Modulo::fromSlug('propina'));
        $this->assertSame('Propina', Modulo::PROPINA->label());
        $this->assertSame(['ver', 'listar', 'criar', 'cancelar', 'anular', 'exportar', 'ajustar'], Modulo::PROPINA->acoesAplicaveis());
    }

    public function test_admin_escola_tem_so_as_acoes_de_f1(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['ver', 'listar', 'criar', 'cancelar', 'exportar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("propina.{$acao}"), $acao);
        }

        foreach (['anular', 'ajustar', 'editar', 'eliminar', 'negociar', 'isentar-multa'] as $acao) {
            $this->assertFalse(Gate::forUser($admin)->allows("propina.{$acao}"), $acao);
        }
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $this->assertFalse(Gate::forUser($this->utilizadorCom($perfil))->allows('propina.ver'), $perfil->name);
        }
    }

    public function test_sincronizar_concede_as_permissoes_a_tenants_existentes_e_invalida_a_cache(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);
        // Simula um tenant criado antes de F1: sem nenhuma permissão de propina.
        RolePermissao::query()->where('modulo_id', ModuloRegistro::where('nome', Modulo::PROPINA->value)->value('id'))->delete();
        $this->assertTrue(Gate::forUser($admin)->denies('propina.ver')); // aquece a cache

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertTrue(Gate::forUser($admin)->allows('propina.ver'));
        $this->assertTrue(Gate::forUser($admin)->allows('propina.cancelar'));
        $this->assertFalse(Gate::forUser($admin)->allows('propina.ajustar'));
    }
}
