<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\EscalaoMulta;
use Modules\Financeiro\Models\RegraCobranca;
use Tests\TestCase;

class FinanceiroTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_contexto_de_tenant_a_leitura_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        RegraCobranca::query()->get();
    }

    public function test_o_scope_devolve_so_a_regra_do_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $minha = RegraCobranca::doTenant();
        $dela = $this->noTenant($outro, fn () => RegraCobranca::doTenant()->id);

        $this->assertNotSame($minha->id, $dela);
        $this->assertSame(1, RegraCobranca::count());
        $this->assertSame($minha->id, RegraCobranca::query()->firstOrFail()->id);
        $this->assertSame($dela, $this->noTenant($outro, fn () => RegraCobranca::query()->firstOrFail()->id));
    }

    public function test_nao_se_muda_o_tenant_de_uma_regra(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $regra = RegraCobranca::doTenant();

        $this->expectException(AlteracaoDeTenantProibida::class);

        $regra->forceFill(['tenant_id' => $outro->id])->save();
    }

    public function test_os_escaloes_de_multa_so_aparecem_no_tenant_dono_e_nao_mudam_de_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $meu = RegraCobranca::doTenant()->escaloes()->create(['ordem' => 1, 'dias_atraso' => 1, 'tipo' => TipoMulta::PERCENTAGEM, 'valor' => 500]);
        $this->noTenant($outro, fn () => RegraCobranca::doTenant()->escaloes()->create(['ordem' => 1, 'dias_atraso' => 2, 'tipo' => TipoMulta::VALOR_FIXO, 'valor' => 900]));

        $this->assertSame([$meu->id], EscalaoMulta::pluck('id')->all());
        $this->assertSame([2], $this->noTenant($outro, fn () => EscalaoMulta::pluck('dias_atraso')->all()));

        $this->expectException(AlteracaoDeTenantProibida::class);

        $meu->forceFill(['tenant_id' => $outro->id])->save();
    }
}
