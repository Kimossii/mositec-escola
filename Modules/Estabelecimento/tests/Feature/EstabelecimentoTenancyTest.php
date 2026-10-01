<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino;
use Tests\TestCase;

class EstabelecimentoTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_devolve_o_estabelecimento_do_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->assertSame('Escola de Teste', Estabelecimento::current()->nome);
        // A memória do tenant anterior não pode vazar para o seguinte.
        $this->assertSame('Escola B', $this->noTenant($outro, fn () => Estabelecimento::current()->nome));
        $this->assertSame('Escola de Teste', Estabelecimento::current()->nome);
    }

    public function test_current_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        Estabelecimento::current();
    }

    public function test_current_consulta_a_bd_uma_so_vez_por_pedido(): void
    {
        app(TenantContext::class)->definir($this->tenant->paraTenantAtual()); // limpa a memória já preenchida pela preparação

        DB::flushQueryLog();
        DB::enableQueryLog();

        Estabelecimento::current();
        Estabelecimento::current();
        Estabelecimento::current();

        $consultas = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'estabelecimentos'));

        $this->assertCount(1, $consultas);
    }

    public function test_current_ignora_is_active(): void
    {
        $estabelecimento = Estabelecimento::current();
        $estabelecimento->update(['is_active' => false]);

        app(TenantContext::class)->definir($this->tenant->paraTenantAtual()); // limpa a memória

        $this->assertSame($estabelecimento->id, Estabelecimento::current()->id);
    }

    public function test_segundo_estabelecimento_no_mesmo_tenant_e_rejeitado_pela_bd(): void
    {
        $this->expectException(QueryException::class);

        Estabelecimento::create(['nome' => 'Duplicado']);
    }

    public function test_etapa_nao_pode_apontar_para_o_estabelecimento_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idDoOutro = $this->noTenant($outro, fn () => Estabelecimento::current()->id);

        $this->expectException(QueryException::class);

        EstabelecimentoEtapaEnsino::create([
            'estabelecimento_id' => $idDoOutro,
            'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO->value,
        ]);
    }

    public function test_etapas_so_aparecem_no_tenant_dono(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        Estabelecimento::current()->etapasEnsino()->create(['etapa_ensino' => EtapaEnsinoEnum::PRIMARIO->value]);

        $this->assertSame(1, EstabelecimentoEtapaEnsino::count());
        $this->assertSame(0, $this->noTenant($outro, fn () => EstabelecimentoEtapaEnsino::count()));
    }

    public function test_nao_se_muda_o_tenant_de_um_estabelecimento(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->expectException(AlteracaoDeTenantProibida::class);

        Estabelecimento::current()->forceFill(['tenant_id' => $outro->id])->save();
    }

    public function test_esta_configurado_segue_configurado_em(): void
    {
        $estabelecimento = Estabelecimento::current();
        $estabelecimento->forceFill(['configurado_em' => null]);

        $this->assertFalse($estabelecimento->estaConfigurado());

        $estabelecimento->forceFill(['configurado_em' => now()]);

        $this->assertTrue($estabelecimento->estaConfigurado());
    }
}
