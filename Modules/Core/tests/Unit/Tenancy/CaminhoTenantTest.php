<?php

namespace Modules\Core\Tests\Unit\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Tests\TestCase;

class CaminhoTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_prefixo_leva_o_id_do_tenant(): void
    {
        $this->assertSame("tenants/{$this->tenant->id}/", CaminhoTenant::prefixo());
    }

    public function test_compoe_o_caminho_sem_barras_duplas(): void
    {
        $id = $this->tenant->id;

        $this->assertSame("tenants/{$id}/alunos/fotos", CaminhoTenant::para('alunos/fotos'));
        $this->assertSame("tenants/{$id}/alunos/fotos", CaminhoTenant::para('alunos//fotos/'));
        $this->assertSame("tenants/{$id}/alunos/fotos", CaminhoTenant::para('./alunos/fotos'));
    }

    public function test_o_prefixo_segue_o_tenant_do_contexto(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->assertSame("tenants/{$outro->id}/x", $this->noTenant($outro, fn () => CaminhoTenant::para('x')));
        $this->assertSame("tenants/{$this->tenant->id}/x", CaminhoTenant::para('x'));
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        CaminhoTenant::para('alunos/fotos');
    }

    public function test_prefixo_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        CaminhoTenant::prefixo();
    }

    /** @dataProvider caminhosInvalidos */
    public function test_rejeita_caminhos_invalidos(string $caminho): void
    {
        $this->expectException(InvalidArgumentException::class);

        CaminhoTenant::para($caminho);
    }

    public static function caminhosInvalidos(): array
    {
        return [
            'sobe um nível' => ['../outro'],
            'sobe no meio' => ['alunos/../../tenants/9/x'],
            'sobe no fim' => ['alunos/..'],
            'só ..' => ['..'],
            'barra invertida' => ['alunos\\..\\x'],
            'absoluto' => ['/etc/passwd'],
            'absoluto windows' => ['C:\\x'],
            'vazio' => [''],
            'só barras' => ['//'],
            'byte nulo' => ["a\0b"],
        ];
    }

    public function test_garantir_aceita_caminhos_sob_o_prefixo_do_tenant(): void
    {
        $caminho = "tenants/{$this->tenant->id}/alunos/fotos/x.jpg";

        $this->assertSame($caminho, CaminhoTenant::garantir($caminho));
    }

    /** @dataProvider caminhosNaoGarantidos */
    public function test_garantir_rejeita(string $caminho): void
    {
        $caminho = str_replace('{id}', (string) $this->tenant->id, $caminho);
        $this->expectException(InvalidArgumentException::class);

        CaminhoTenant::garantir($caminho);
    }

    public static function caminhosNaoGarantidos(): array
    {
        return [
            'outro tenant' => ['tenants/999/x'],
            'prefixo parcial (id 1 vs 10..)' => ['tenants/{id}0/x'],
            'sobe para outro tenant' => ['tenants/{id}/../999/x'],
            'sem prefixo' => ['alunos/fotos/x.jpg'],
            'vazio' => [''],
            'só o prefixo' => ['tenants/{id}/'],
            'absoluto' => ['/tenants/{id}/x'],
            'barra invertida' => ['tenants/{id}/a\\..\\b'],
        ];
    }

    public function test_garantir_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        CaminhoTenant::garantir('tenants/1/x');
    }
}
