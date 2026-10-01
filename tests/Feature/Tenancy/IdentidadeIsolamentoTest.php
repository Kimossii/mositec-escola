<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class IdentidadeIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(string $email): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => $email, 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        return $user;
    }

    public function test_a_listagem_de_utilizadores_mostra_so_os_do_tenant_do_dominio(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $admin = $this->administrador('admin-a@example.com');
        User::create(['name' => 'So em A', 'email' => 'so-em-a@example.com', 'password' => Hash::make('x')]);
        $this->noTenant($outro, fn () => User::create(['name' => 'So em B', 'email' => 'so-em-b@example.com', 'password' => Hash::make('x')]));

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/usuarios'));

        $resposta->assertOk();
        $this->assertStringContainsString('admin-a@example.com', $resposta->getContent());
        $this->assertStringContainsString('so-em-a@example.com', $resposta->getContent());
        $this->assertStringNotContainsString('so-em-b@example.com', $resposta->getContent());
    }

    public function test_pedir_no_dominio_de_a_um_utilizador_de_b_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $admin = $this->administrador('admin-a@example.com');
        $idDeB = $this->noTenant($outro, fn () => User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('x')])->id);

        $idDeA = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('x')])->id;

        // Controlo positivo: a mesma rota responde 200 para um utilizador do próprio tenant.
        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, "/usuarios/{$idDeA}/editar"))
            ->assertOk();

        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, "/usuarios/{$idDeB}/editar"))
            ->assertNotFound();
    }

    public function test_o_administrador_de_a_nao_acede_ao_dominio_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $admin = $this->administrador('admin-a@example.com');

        // Sessão como a que o login cria: com o tenant de origem.
        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($admin)
            ->get($this->urlDoTenant($outro, '/usuarios'))
            ->assertRedirect();

        $this->assertGuest();
    }
}
