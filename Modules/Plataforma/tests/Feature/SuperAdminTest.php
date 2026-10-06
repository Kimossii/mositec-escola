<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Plataforma\Actions\CriarSuperAdminAction;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tabela_nao_tem_tenant_id_e_o_model_nao_pertence_a_tenant(): void
    {
        $this->assertFalse(Schema::hasColumn('super_admins', 'tenant_id'));
        $this->assertFalse(Schema::hasColumn('plataforma_auditoria', 'tenant_id'));
        $this->assertNotContains(PertenceAoTenant::class, class_uses_recursive(SuperAdmin::class));
        $this->assertNotContains(PertenceAoTenant::class, class_uses_recursive(RegistoDeAuditoria::class));
    }

    public function test_as_duas_tabelas_sao_globais(): void
    {
        $this->assertContains('super_admins', config('tenancy.tabelas_globais'));
        $this->assertContains('plataforma_auditoria', config('tenancy.tabelas_globais'));
    }

    public function test_cria_se_sem_contexto_de_tenant_e_com_contexto_aberto(): void
    {
        app(TenantContext::class)->limpar();
        $this->assertFalse(app(TenantContext::class)->temTenant());

        $semContexto = SuperAdmin::create(['name' => 'Sem Contexto', 'email' => 'a@plataforma.test', 'password' => 'x']);

        app(TenantContext::class)->definir($this->tenant->paraTenantAtual());
        $comContexto = SuperAdmin::create(['name' => 'Com Contexto', 'email' => 'b@plataforma.test', 'password' => 'x']);

        $this->assertNotNull($semContexto->id);
        $this->assertNotNull($comContexto->id);
        $this->assertArrayNotHasKey('tenant_id', $comContexto->fresh()->getAttributes());
    }

    public function test_o_email_e_unico_globalmente(): void
    {
        SuperAdmin::create(['name' => 'Um', 'email' => 'mesmo@plataforma.test', 'password' => 'x']);

        $this->expectException(UniqueConstraintViolationException::class);
        SuperAdmin::create(['name' => 'Dois', 'email' => 'mesmo@plataforma.test', 'password' => 'x']);
    }

    public function test_o_estado_descricao_fica_sincronizado(): void
    {
        $admin = SuperAdmin::create(['name' => 'Um', 'email' => 'um@plataforma.test', 'password' => 'x']);
        $this->assertSame(1, (int) $admin->fresh()->estado);
        $this->assertSame('Ativo', $admin->fresh()->estado_descricao);

        $admin->update(['estado' => 0]);
        $this->assertSame('Inativo', $admin->fresh()->estado_descricao);
    }

    public function test_por_omissao_nao_obriga_troca_e_nunca_entrou(): void
    {
        $admin = SuperAdmin::create(['name' => 'Um', 'email' => 'um@plataforma.test', 'password' => 'x'])->fresh();

        $this->assertFalse($admin->deve_alterar_senha);
        $this->assertNull($admin->ultimo_login_em);
    }

    public function test_a_senha_so_fica_em_hash_e_nao_sai_na_serializacao(): void
    {
        $credencial = app(CriarSuperAdminAction::class)->executar('Operador', 'op@plataforma.test');
        $admin = SuperAdmin::where('email', 'op@plataforma.test')->sole();

        $this->assertNotSame($credencial->senha(), $admin->password);
        $this->assertTrue(password_verify($credencial->senha(), $admin->password));
        $this->assertArrayNotHasKey('password', $admin->toArray());
        $this->assertArrayNotHasKey('remember_token', $admin->toArray());
        $this->assertStringNotContainsString($credencial->senha(), $admin->toJson());
    }

    public function test_o_model_e_autenticavel_e_nao_e_um_model_de_tenant(): void
    {
        $this->assertInstanceOf(Model::class, new SuperAdmin());
        $this->assertInstanceOf(\Illuminate\Contracts\Auth\Authenticatable::class, new SuperAdmin());
    }

    public function test_o_guard_plataforma_usa_o_provider_dos_super_admins_e_o_guard_por_omissao_continua_web(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertSame('session', config('auth.guards.plataforma.driver'));
        $this->assertSame('super_admins', config('auth.guards.plataforma.provider'));
        $this->assertSame('eloquent', config('auth.providers.super_admins.driver'));
        $this->assertSame(SuperAdmin::class, config('auth.providers.super_admins.model'));
        $this->assertSame('users', config('auth.guards.web.provider'));
    }
}
