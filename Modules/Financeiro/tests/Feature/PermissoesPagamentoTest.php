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

class PermissoesPagamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizadorCom(Perfil $perfil): User
    {
        $user = User::create(['name' => 'Teste', 'email' => $perfil->value . '-pag@example.com', 'password' => Hash::make('x')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);

        return $user;
    }

    public function test_modulo_tem_slug_label_e_acoes_proprias(): void
    {
        $this->assertSame(23, Modulo::PAGAMENTO->value);
        $this->assertSame('pagamento', Modulo::PAGAMENTO->slug());
        $this->assertSame(Modulo::PAGAMENTO, Modulo::fromSlug('pagamento'));
        $this->assertSame('Pagamento', Modulo::PAGAMENTO->label());
        $this->assertSame(['ver', 'listar', 'criar', 'anular', 'exportar'], Modulo::PAGAMENTO->acoesAplicaveis());
    }

    public function test_admin_escola_tem_as_acoes_de_pagamento_e_nunca_editar_nem_eliminar(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['ver', 'listar', 'criar', 'anular', 'exportar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("pagamento.{$acao}"), $acao);
        }

        foreach (['editar', 'eliminar', 'confirmar'] as $acao) {
            $this->assertFalse(Gate::forUser($admin)->allows("pagamento.{$acao}"), $acao);
        }
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $this->assertFalse(Gate::forUser($this->utilizadorCom($perfil))->allows('pagamento.ver'), $perfil->name);
        }
    }

    public function test_sincronizar_concede_pagamento_e_propina_anular_a_tenants_existentes(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);
        // Simula um tenant criado antes de Pagamentos-A: sem pagamento.* nem propina.anular.
        RolePermissao::query()->where('modulo_id', ModuloRegistro::where('nome', Modulo::PAGAMENTO->value)->value('id'))->delete();
        RolePermissao::query()
            ->where('modulo_id', ModuloRegistro::where('nome', Modulo::PROPINA->value)->value('id'))
            ->whereHas('acao', fn ($acao) => $acao->where('nome', 'anular'))
            ->delete();
        $this->assertTrue(Gate::forUser($admin)->denies('pagamento.ver')); // aquece a cache
        $this->assertTrue(Gate::forUser($admin)->denies('propina.anular'));

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertTrue(Gate::forUser($admin)->allows('pagamento.criar'));
        $this->assertTrue(Gate::forUser($admin)->allows('pagamento.anular'));
        $this->assertTrue(Gate::forUser($admin)->allows('propina.anular'));
    }
}
