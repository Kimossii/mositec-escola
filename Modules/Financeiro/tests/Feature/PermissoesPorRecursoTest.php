<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesPorRecursoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    /** Utilizador com um perfil personalizado que só tem as quatro acções do módulo indicado. */
    private function utilizadorSoCom(Modulo $modulo): User
    {
        $role = Role::create(['nome' => 99, 'descricao' => 'Só ' . $modulo->slug()]);
        $registo = ModuloRegistro::where('nome', $modulo->value)->firstOrFail();

        foreach (Acao::all() as $acao) {
            RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $registo->id, 'acao_id' => $acao->id]);
        }

        $user = User::create(['name' => 'Teste', 'email' => 'recurso@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_quem_so_tem_metodo_pagamento_nao_acede_a_planos_nem_regras(): void
    {
        $this->actingAs($this->utilizadorSoCom(Modulo::METODO_PAGAMENTO));

        $this->get(route('financeiro.configuracao.metodos-pagamento.index'))->assertOk();
        $this->get(route('financeiro.configuracao.produtos-servicos.index'))->assertForbidden();
        $this->get(route('financeiro.configuracao.regras-cobranca.show'))->assertForbidden();
        $this->post(route('financeiro.configuracao.produtos.store'), [])->assertForbidden();
    }

    public function test_quem_so_tem_catalogo_financeiro_nao_acede_a_metodos_nem_regras(): void
    {
        $this->actingAs($this->utilizadorSoCom(Modulo::CATALOGO_FINANCEIRO));

        $this->get(route('financeiro.configuracao.produtos-servicos.index'))->assertOk();
        $this->get(route('financeiro.configuracao.metodos-pagamento.index'))->assertForbidden();
        $this->get(route('financeiro.configuracao.regras-cobranca.show'))->assertForbidden();
    }
}
