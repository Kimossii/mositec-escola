<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Financeiro\Provisioning\ProvisionarRegrasCobranca;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RegraCobrancaProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function dados(): DadosProvisionamento
    {
        return new DadosProvisionamento('Escola', 'Admin', 'admin@example.com');
    }

    public function test_provisionador_esta_registado_e_ordem_e_unica(): void
    {
        $provisionadores = collect(app()->tagged(ProvisionaTenant::ETIQUETA));

        $this->assertTrue($provisionadores->contains(fn ($p) => $p instanceof ProvisionarRegrasCobranca));
        $this->assertSame(
            $provisionadores->count(),
            $provisionadores->map(fn (ProvisionaTenant $p) => $p->ordem())->unique()->count(),
        );
    }

    public function test_provisionar_duas_vezes_nao_duplica(): void
    {
        $provisionador = app(ProvisionarRegrasCobranca::class);

        $provisionador->provisionar($this->tenant->paraTenantAtual(), $this->dados());
        $provisionador->provisionar($this->tenant->paraTenantAtual(), $this->dados());

        $this->assertSame(1, DB::table('regras_cobranca')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_comando_cria_as_regras_em_falta_e_concede_permissoes(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        DB::table('role_permissoes')->delete();
        $this->assertSame(0, DB::table('regras_cobranca')->count());

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertSame(1, DB::table('regras_cobranca')->where('tenant_id', $this->tenant->id)->count());

        $ids = [
            'role_id' => Role::where('nome', Perfil::ADMIN_ESCOLA->value)->value('id'),
            'modulo_id' => ModuloRegistro::where('nome', Modulo::REGRA_COBRANCA->value)->value('id'),
            'acao_id' => Acao::where('nome', 'ver')->value('id'),
        ];

        $this->assertNotContains(null, $ids);
        $this->assertTrue(RolePermissao::where($ids)->exists());
        $this->assertSame(1, RolePermissao::where($ids)->count());
    }

    public function test_comando_exige_tenant_ou_todos(): void
    {
        $this->artisan('financeiro:sincronizar')->assertFailed();
    }

    public function test_backfill_invalida_a_cache_de_permissoes_dos_admins_existentes(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        DB::table('role_permissoes')->delete();

        $admin = User::create(['name' => 'Admin', 'email' => 'admin-backfill@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        // Aquece a cache de permissões do utilizador (conjunto vazio).
        $this->assertTrue(Gate::forUser($admin)->denies('regra-cobranca.ver'));

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertTrue(Gate::forUser($admin)->allows('regra-cobranca.ver'));
    }
}
