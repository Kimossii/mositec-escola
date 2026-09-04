<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GateBeforeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuloSeeder::class);
        $this->seed(AcaoSeeder::class);
    }

    public function test_ability_reconhecida_e_concedida_via_gate(): void
    {
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste']);
        $modulo = ModuloRegistro::where('nome', 6)->first();
        $acao = Acao::where('nome', 'criar')->first();
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);

        $user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        $this->assertTrue(Gate::forUser($user)->allows('turmas.criar'));
    }

    public function test_ability_nao_reconhecida_nao_e_interceptada(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u2@example.com', 'password' => Hash::make('x')]);

        // Sem ponto: o nosso Gate::before devolve null e o Laravel segue o
        // caminho normal — sem ability nem Policy registada para 'algo-qualquer',
        // o resultado por omissão do Laravel é negar.
        $this->assertFalse(Gate::forUser($user)->allows('algo-qualquer'));
    }

    public function test_ability_reconhecida_e_negada_vence_mesmo_com_outro_gate_before_a_conceder_tudo(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u3@example.com', 'password' => Hash::make('x')]);
        // Sem role nenhuma atribuída -> o nosso resolver nega 'turmas.criar'.

        // Um segundo Gate::before, registado DEPOIS do nosso, que concederia
        // tudo -- simula um bypass futuro (ex: super-admin) mal desenhado.
        Gate::before(fn () => true);

        $this->assertFalse(Gate::forUser($user)->allows('turmas.criar'));
    }
}
