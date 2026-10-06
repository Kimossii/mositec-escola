<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Models\Horario;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class HorarioTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    private function administrador(): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => 'admin-a@example.com', 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        return $user;
    }

    private function horario(string $nome): Horario
    {
        return Horario::create(['nome' => $nome, 'hora_inicio' => '08:00', 'hora_fim' => '09:00']);
    }

    public function test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $this->horario('Horario So Em A');
        $this->noTenant($this->outro, fn () => $this->horario('Horario So Em B'));

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/horarios'));

        $resposta->assertOk();
        $this->assertStringContainsString('Horario So Em A', $resposta->getContent());
        $this->assertStringNotContainsString('Horario So Em B', $resposta->getContent());
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $idDeA = $this->horario('A')->id;
        $idDeB = $this->noTenant($this->outro, fn () => $this->horario('B')->id);
        $dados = ['nome' => 'Novo', 'hora_inicio' => '10:00', 'hora_fim' => '11:00'];

        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/horarios/{$idDeA}"), $dados)
            ->assertRedirect();

        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/horarios/{$idDeB}"), $dados)
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete($this->urlDoTenant($this->tenant, "/horarios/{$idDeB}"))
            ->assertNotFound();

        $this->assertSame('B', $this->noTenant($this->outro, fn () => Horario::find($idDeB)->nome));
    }

    public function test_exists_rejeita_o_id_de_outro_tenant(): void
    {
        $idDeA = $this->horario('A')->id;
        $idDeB = $this->noTenant($this->outro, fn () => $this->horario('B')->id);

        $this->assertTrue(Validator::make(['x' => $idDeA], ['x' => 'exists:horarios,id'])->passes());
        $this->assertTrue(Validator::make(['x' => $idDeB], ['x' => 'exists:horarios,id'])->fails());
    }

    public function test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao(): void
    {
        $horario = $this->horario('A');
        $this->assertSame($this->tenant->id, (int) $horario->fresh()->tenant_id);

        $horario = $horario->fresh();
        $horario->tenant_id = $this->outro->id;

        $this->expectException(AlteracaoDeTenantProibida::class);
        $horario->save();
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        $horario = $this->horario('A');

        app(TenantContext::class)->limpar();

        foreach ([
            fn () => Horario::query()->get(),
            fn () => $this->horario('X'),
            fn () => $horario->update(['nome' => 'Y']),
            fn () => $horario->delete(),
        ] as $operacao) {
            try {
                $operacao();
                $this->fail('Operar sem contexto devia lançar TenantNaoResolvido.');
            } catch (TenantNaoResolvido) {
                $this->assertTrue(true);
            }
        }
    }
}
