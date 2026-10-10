<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\EscalaoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class FinanceiroTenancyTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
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

    private function umaPropina(): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano);
    }

    public function test_as_propinas_so_aparecem_no_tenant_dono_e_nao_mudam_de_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $minha = $this->umaPropina();
        $dela = $this->noTenant($outro, fn () => $this->umaPropina()->id);

        $this->assertSame([$minha->id], Propina::query()->pluck('id')->all());
        $this->assertNull(Propina::query()->find($dela));
        $this->assertSame([$dela], $this->noTenant($outro, fn () => Propina::query()->pluck('id')->all()));

        $this->expectException(AlteracaoDeTenantProibida::class);

        $minha->forceFill(['tenant_id' => $outro->id])->save();
    }

    public function test_sem_contexto_ler_ou_gravar_propinas_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        try {
            Propina::query()->count();
            $this->fail('A leitura sem contexto devia lançar.');
        } catch (TenantNaoResolvido) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(TenantNaoResolvido::class);

        (new Propina())->forceFill([])->save();
    }

    public function test_uma_propina_nao_aceita_matricula_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        ['plano' => $plano] = $this->cenarioPropinas();
        $matriculaDela = $this->noTenant($outro, fn () => $this->cenarioPropinas()['matricula']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A matrícula e o plano têm de existir no tenant da propina.');

        $this->propina($matriculaDela, $plano);
    }
}
