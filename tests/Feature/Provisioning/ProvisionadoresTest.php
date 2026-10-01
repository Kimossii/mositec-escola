<?php

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Provisioning\ProvisionarAdministradorInicial;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\ColectorDeCredenciais;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Provisioning\ProvisionarEstabelecimento;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Provisioning\ProvisionarPerfis;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Modules\Usuario\Provisioning\ProvisionarTiposDocumento;
use Tests\TestCase;

/** Cada provisionador isolado, num tenant B novo; o tenant A (o de teste) nunca é afectado. */
class ProvisionadoresTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $b;

    private DadosProvisionamento $dados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);

        $this->b = Tenant::create(['codigo' => 'MOSI-000050', 'nome' => 'Escola B']);
        $this->dados = new DadosProvisionamento('Escola B', 'Ana Admin', 'ana@escola-b.test');
    }

    private function provisionarEmB(ProvisionaTenant $provisionador): void
    {
        $this->noTenant($this->b, fn () => $provisionador->provisionar($this->b->paraTenantAtual(), $this->dados));
    }

    public function test_as_ordens_sao_10_20_30_40(): void
    {
        $this->assertSame(10, app(ProvisionarEstabelecimento::class)->ordem());
        $this->assertSame(20, app(ProvisionarPerfis::class)->ordem());
        $this->assertSame(30, app(ProvisionarTiposDocumento::class)->ordem());
        $this->assertSame(40, app(ProvisionarAdministradorInicial::class)->ordem());
    }

    public function test_estabelecimento_minimo_so_com_o_nome_e_nao_configurado(): void
    {
        $this->provisionarEmB(app(ProvisionarEstabelecimento::class));

        $this->noTenant($this->b, function () {
            $this->assertSame(1, Estabelecimento::count());
            $estabelecimento = Estabelecimento::current();
            $this->assertSame('Escola B', $estabelecimento->nome);
            $this->assertNull($estabelecimento->configurado_em);
        });
        $this->assertSame(1, Estabelecimento::count(), 'O tenant A mantém o seu único estabelecimento.');
    }

    public function test_perfis_e_permissoes_do_tenant_sem_afectar_o_outro(): void
    {
        $this->provisionarEmB(app(ProvisionarPerfis::class));

        $this->noTenant($this->b, function () {
            $this->assertSame(count(Perfil::cases()), Role::count());
            $admin = Role::where('nome', Perfil::ADMIN_ESCOLA->value)->sole();
            $this->assertGreaterThan(0, RolePermissao::where('role_id', $admin->id)->count());
            $this->assertGreaterThan(0, RolePermissao::count());
        });

        $this->assertSame(0, Role::count(), 'O tenant A não ganha perfis.');
        $this->assertSame(0, RolePermissao::count());
    }

    public function test_perfis_sem_catalogo_global_falham_em_vez_de_criar_um_admin_sem_permissoes(): void
    {
        DB::table('acoes')->delete();
        DB::table('modulos')->delete();

        try {
            $this->provisionarEmB(app(ProvisionarPerfis::class));
            $this->fail('Devia lançar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ANO_LECTIVO', $e->getMessage(), 'Usa o nome do módulo, não o inteiro.');
            $this->assertStringContainsString('php artisan db:seed --force', $e->getMessage());
        }
    }

    public function test_o_admin_dev_seeder_nao_faz_nada_fora_de_desenvolvimento(): void
    {
        $this->seed(\Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder::class);
        $this->app['env'] = 'production';

        app(\Modules\Autenticacao\Database\Seeders\AdminUserSeeder::class)->run();

        $this->assertSame(0, User::count());
    }

    public function test_tipos_de_documento_por_omissao_e_idempotente(): void
    {
        $this->provisionarEmB(app(ProvisionarTiposDocumento::class));
        $this->provisionarEmB(app(ProvisionarTiposDocumento::class));

        $this->noTenant($this->b, fn () => $this->assertSame(6, TipoDocumento::count()));
        $this->assertSame(0, TipoDocumento::count());
    }

    public function test_administrador_inicial_com_perfil_flag_e_so_hash(): void
    {
        $this->provisionarEmB(app(ProvisionarPerfis::class));
        $this->provisionarEmB(app(ProvisionarAdministradorInicial::class));

        $credencial = app(ColectorDeCredenciais::class)->retirar();
        $this->assertNotNull($credencial);
        $this->assertSame('ana@escola-b.test', $credencial->email);
        $this->assertSame(14, strlen($credencial->senha()));

        $this->noTenant($this->b, function () use ($credencial) {
            $admin = User::where('email', 'ana@escola-b.test')->sole();
            $this->assertSame('Ana Admin', $admin->name);
            $this->assertTrue($admin->deve_alterar_senha);
            $this->assertSame([Perfil::ADMIN_ESCOLA->value], $admin->roles()->pluck('nome')->all());
            $this->assertTrue(Hash::check($credencial->senha(), $admin->password));
            $this->assertNotSame($credencial->senha(), $admin->password);
        });

        $this->assertSame(0, User::count(), 'O tenant A não ganha utilizadores.');
        $this->assertNull(app(ColectorDeCredenciais::class)->retirar(), 'A credencial só sai uma vez.');
    }

    public function test_a_senha_em_claro_nao_esta_em_lado_nenhum_da_base_de_dados(): void
    {
        $this->provisionarEmB(app(ProvisionarPerfis::class));
        $this->provisionarEmB(app(ProvisionarAdministradorInicial::class));
        $senha = app(ColectorDeCredenciais::class)->retirar()->senha();

        foreach (['users', 'sessions', 'roles'] as $tabela) {
            $this->assertStringNotContainsString($senha, json_encode(DB::table($tabela)->get()), "Senha em claro em {$tabela}.");
        }
    }

    public function test_a_credencial_nao_se_deixa_serializar_nem_mostrar(): void
    {
        $this->provisionarEmB(app(ProvisionarPerfis::class));
        $this->provisionarEmB(app(ProvisionarAdministradorInicial::class));
        $credencial = app(ColectorDeCredenciais::class)->retirar();

        $this->assertStringNotContainsString($credencial->senha(), print_r($credencial, true));
        $this->assertStringNotContainsString($credencial->senha(), var_export((array) $credencial->__debugInfo(), true));
        $this->expectException(\LogicException::class);
        serialize($credencial);
    }
}
