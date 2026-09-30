<?php

namespace Modules\Tenant\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Enums\TipoDominio;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class TenantModelTest extends TestCase
{
    use RefreshDatabase;

    private function novoTenant(string $codigo = 'MOSI-000900'): Tenant
    {
        return Tenant::create(['codigo' => $codigo, 'nome' => 'Colégio São José']);
    }

    public function test_tabelas_tem_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasColumns('tenants', [
            'id', 'codigo', 'nome', 'estado', 'estado_descricao',
            'suspenso_em', 'motivo_suspensao', 'encerrado_em', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('domains', [
            'id', 'tenant_id', 'dominio', 'tipo', 'tipo_descricao', 'is_principal', 'created_at', 'updated_at',
        ]));
    }

    public function test_tenant_nasce_activo_com_descricao_sincronizada(): void
    {
        $tenant = $this->novoTenant()->fresh();

        $this->assertSame(EstadoTenant::ACTIVO, $tenant->estado);
        $this->assertSame('Activo', $tenant->estado_descricao);
    }

    public function test_mudar_o_estado_actualiza_a_descricao(): void
    {
        $tenant = $this->novoTenant();
        $tenant->update(['estado' => EstadoTenant::SUSPENSO]);

        $this->assertSame('Suspenso', $tenant->fresh()->estado_descricao);
    }

    public function test_codigo_e_unico(): void
    {
        $this->novoTenant('MOSI-000900');

        $this->expectException(QueryException::class);
        $this->novoTenant('MOSI-000900');
    }

    public function test_codigo_e_imutavel(): void
    {
        $tenant = $this->novoTenant();

        $this->expectException(LogicException::class);
        $tenant->update(['codigo' => 'MOSI-000901']);
    }

    public function test_para_tenant_atual(): void
    {
        $tenant = $this->novoTenant();
        $atual = $tenant->paraTenantAtual();

        $this->assertSame($tenant->id, $atual->id);
        $this->assertSame('MOSI-000900', $atual->codigo);
        $this->assertSame('Colégio São José', $atual->nome);
        $this->assertSame(EstadoTenant::ACTIVO, $atual->estado);
    }

    public function test_dominio_e_guardado_normalizado_com_tipo_por_omissao(): void
    {
        $dominio = $this->novoTenant()->dominios()->create(['dominio' => 'Colegio-SJ.MosiTec.AO:443'])->fresh();

        $this->assertSame('colegio-sj.mositec.ao', $dominio->dominio);
        $this->assertSame(TipoDominio::SUBDOMINIO, $dominio->tipo);
        $this->assertSame('Subdomínio', $dominio->tipo_descricao);
        $this->assertFalse($dominio->is_principal);
    }

    public function test_dominio_e_unico_entre_tenants(): void
    {
        $this->novoTenant('MOSI-000900')->dominios()->create(['dominio' => 'igual.mositec.ao']);

        $this->expectException(QueryException::class);
        $this->novoTenant('MOSI-000901')->dominios()->create(['dominio' => 'IGUAL.mositec.ao']);
    }

    public function test_so_pode_haver_um_dominio_principal_por_tenant(): void
    {
        $tenant = $this->novoTenant();
        $tenant->dominios()->create(['dominio' => 'um.mositec.ao', 'is_principal' => true]);
        $tenant->dominios()->create(['dominio' => 'dois.mositec.ao', 'is_principal' => false]);

        $this->expectException(QueryException::class);
        $tenant->dominios()->create(['dominio' => 'tres.mositec.ao', 'is_principal' => true]);
    }

    public function test_tenants_diferentes_podem_ter_cada_um_o_seu_principal(): void
    {
        $this->novoTenant('MOSI-000900')->dominios()->create(['dominio' => 'a.mositec.ao', 'is_principal' => true]);
        $this->novoTenant('MOSI-000901')->dominios()->create(['dominio' => 'b.mositec.ao', 'is_principal' => true]);

        $this->assertSame(2, Domain::query()->whereIn('dominio', ['a.mositec.ao', 'b.mositec.ao'])->where('is_principal', true)->count());
    }
}
