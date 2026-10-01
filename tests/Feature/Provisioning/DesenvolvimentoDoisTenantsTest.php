<?php

namespace Tests\Feature\Provisioning;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Horario;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Models\Sala;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Models\Turno;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** (i) O seed de desenvolvimento: dois tenants isolados, cada admin entra só no seu domínio. */
class DesenvolvimentoDoisTenantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O seed corre numa BD acabada de migrar, sem o tenant da base de testes.
        DB::table('domains')->delete();
        DB::table('estabelecimentos')->delete();
        DB::table('tenants')->delete();
        app(TenantContext::class)->limpar();

        $this->seed(DatabaseSeeder::class);
    }

    private function tenant(string $codigo): Tenant
    {
        return Tenant::where('codigo', $codigo)->sole();
    }

    public function test_cria_dois_tenants_com_os_seus_dominios_e_deixa_o_contexto_limpo(): void
    {
        $this->assertSame(['MOSI-000001', 'MOSI-000002'], Tenant::orderBy('codigo')->pluck('codigo')->all());
        $this->assertEqualsCanonicalizing(['localhost', '127.0.0.1'], $this->tenant('MOSI-000001')->dominios()->pluck('dominio')->all());
        $this->assertSame(['escola-b.mositec-escola.test'], $this->tenant('MOSI-000002')->dominios()->pluck('dominio')->all());
        $this->assertFalse(app(TenantContext::class)->temTenant());
    }

    public function test_cada_tenant_tem_o_seu_provisioning_e_os_dados_de_demonstracao_sem_colisao(): void
    {
        foreach (['MOSI-000001', 'MOSI-000002'] as $codigo) {
            $this->noTenant($this->tenant($codigo), function () {
                $this->assertSame(1, Estabelecimento::count());
                $this->assertNull(Estabelecimento::current()->configurado_em);
                $this->assertSame(5, Role::count());
                $this->assertSame(6, TipoDocumento::count());
                $this->assertSame(1, User::count());
                $this->assertSame(28, Disciplina::count());
                $this->assertSame(12, Curso::count());
                $this->assertSame(3, Turno::count());
                $this->assertGreaterThan(0, Sala::count());
                $this->assertGreaterThan(0, Horario::count());
            });
        }

        $this->assertNotSame(
            $this->noTenant($this->tenant('MOSI-000001'), fn () => Estabelecimento::current()->id),
            $this->noTenant($this->tenant('MOSI-000002'), fn () => Estabelecimento::current()->id),
        );
    }

    public function test_o_admin_de_cada_tenant_entra_no_seu_dominio_e_nao_no_do_outro(): void
    {
        $this->post('http://localhost/login', ['login' => 'admin@mositec.gmail.com', 'password' => '12345678'])->assertRedirect('/');
        $this->get('http://localhost/')->assertRedirect(route('estabelecimento.dados'));
        $this->post('http://localhost/logout');
        $this->assertGuest();

        $this->post('http://escola-b.mositec-escola.test/login', ['login' => 'admin@escola-b.mositec.test', 'password' => '12345678']);
        $this->get('http://escola-b.mositec-escola.test/estabelecimento')->assertOk();
        $this->post('http://escola-b.mositec-escola.test/logout');
        $this->assertGuest();

        // Cruzado: credenciais de um no domínio do outro.
        $this->post('http://escola-b.mositec-escola.test/login', ['login' => 'admin@mositec.gmail.com', 'password' => '12345678'])
            ->assertSessionHasErrors();
        $this->assertGuest();
        $this->post('http://localhost/login', ['login' => 'admin@escola-b.mositec.test', 'password' => '12345678'])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_os_admins_de_dev_nao_tem_troca_obrigatoria(): void
    {
        foreach (['MOSI-000001', 'MOSI-000002'] as $codigo) {
            $this->noTenant($this->tenant($codigo), fn () => $this->assertFalse(User::sole()->deve_alterar_senha));
        }
    }

    public function test_e_idempotente(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Tenant::count());
        $this->noTenant($this->tenant('MOSI-000002'), function () {
            $this->assertSame(1, User::count());
            $this->assertSame(5, Role::count());
            $this->assertSame(28, Disciplina::count());
        });
    }

    public function test_um_novo_seed_repoe_permissoes_e_tipos_em_falta_nos_tenants_existentes(): void
    {
        foreach (['MOSI-000001', 'MOSI-000002'] as $codigo) {
            $this->noTenant($this->tenant($codigo), function () {
                \Modules\Permissao\Models\RolePermissao::query()->limit(1)->delete();
                TipoDocumento::where('slug', 'bi')->delete();
            });
        }

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $esperado = null;
        foreach (['MOSI-000001', 'MOSI-000002'] as $codigo) {
            $contagem = $this->noTenant($this->tenant($codigo), function () {
                $this->assertTrue(TipoDocumento::where('slug', 'bi')->exists());

                return \Modules\Permissao\Models\RolePermissao::count();
            });
            $esperado ??= $contagem;
            $this->assertSame($esperado, $contagem);
            $this->noTenant($this->tenant($codigo), fn () => $this->assertSame(6, TipoDocumento::count()));
        }
    }
}
