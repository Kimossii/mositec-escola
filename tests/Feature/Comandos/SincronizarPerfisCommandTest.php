<?php

namespace Tests\Feature\Comandos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Actions\SincronizarPerfisDeSistemaAction;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Models\RolePermissao;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Actions\CriarTiposDocumentoPadraoAction;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class SincronizarPerfisCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        $this->a = $this->criarTenant('MOSI-000010', 'Escola A', 'a.localhost');
        $this->b = $this->criarTenant('MOSI-000011', 'Escola B', 'b.localhost');

        foreach ([$this->a, $this->b] as $tenant) {
            $this->noTenant($tenant, function () {
                app(SincronizarPerfisDeSistemaAction::class)->executar();
                app(CriarTiposDocumentoPadraoAction::class)->executar();
            });
        }
    }

    private function permissoes(Tenant $tenant): int
    {
        return $this->noTenant($tenant, fn () => RolePermissao::count());
    }

    private function tiposDocumento(Tenant $tenant): int
    {
        return $this->noTenant($tenant, fn () => TipoDocumento::count());
    }

    private function estragar(Tenant $tenant): void
    {
        $this->noTenant($tenant, function () {
            RolePermissao::query()->limit(3)->get()->each(fn ($p) => RolePermissao::query()->where('role_id', $p->role_id)->where('modulo_id', $p->modulo_id)->where('acao_id', $p->acao_id)->delete());
            TipoDocumento::where('slug', 'bi')->delete();
        });
    }

    public function test_repoe_o_que_falta_so_no_tenant_alvo(): void
    {
        $completoPermissoes = $this->permissoes($this->a);
        $completoTipos = $this->tiposDocumento($this->a);
        $this->estragar($this->a);
        $this->estragar($this->b);
        $this->assertLessThan($completoPermissoes, $this->permissoes($this->a));

        $this->artisan('mosi:tenant:sync-perfis', ['--tenant' => 'MOSI-000010'])->assertSuccessful();

        $this->assertSame($completoPermissoes, $this->permissoes($this->a));
        $this->assertSame($completoTipos, $this->tiposDocumento($this->a));
        $this->assertSame($completoPermissoes - 3, $this->permissoes($this->b), 'O outro tenant fica intacto.');
        $this->assertSame($completoTipos - 1, $this->tiposDocumento($this->b));
    }

    public function test_e_idempotente(): void
    {
        $permissoes = $this->permissoes($this->a);
        $tipos = $this->tiposDocumento($this->a);

        $this->artisan('mosi:tenant:sync-perfis', ['--tenant' => 'MOSI-000010'])->assertSuccessful();
        $this->artisan('mosi:tenant:sync-perfis', ['--todos' => true])->assertSuccessful();

        $this->assertSame($permissoes, $this->permissoes($this->a));
        $this->assertSame($tipos, $this->tiposDocumento($this->a));
    }

    public function test_todos_repoe_em_todos_os_activos(): void
    {
        $completo = $this->permissoes($this->a);
        $this->estragar($this->a);
        $this->estragar($this->b);

        $this->artisan('mosi:tenant:sync-perfis', ['--todos' => true])->assertSuccessful();

        $this->assertSame($completo, $this->permissoes($this->a));
        $this->assertSame($completo, $this->permissoes($this->b));
    }

    public function test_sem_opcao_falha(): void
    {
        $this->artisan('mosi:tenant:sync-perfis')->assertFailed();
    }
}
