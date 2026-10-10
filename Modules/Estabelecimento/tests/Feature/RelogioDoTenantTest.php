<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Tests\TestCase;

class RelogioDoTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function congelar(string $instante): void
    {
        Carbon::setTestNow(CarbonImmutable::parse($instante, 'UTC'));
    }

    private function relogio(): RelogioDoTenant
    {
        return app(RelogioDoTenant::class);
    }

    public function test_o_fuso_por_omissao_e_africa_luanda_nos_tenants_existentes(): void
    {
        $this->assertSame('Africa/Luanda', Estabelecimento::FUSO_HORARIO_PADRAO);
        $this->assertSame('Africa/Luanda', Estabelecimento::current()->fuso_horario);
        $this->assertDatabaseHas('estabelecimentos', ['fuso_horario' => 'Africa/Luanda']);
    }

    public function test_hoje_e_o_dia_civil_em_luanda_quando_em_utc_ainda_e_o_dia_anterior(): void
    {
        $this->congelar('2026-10-10 23:30:00');

        $hoje = $this->relogio()->hoje();

        $this->assertSame('2026-10-11', $hoje->toDateString());
        $this->assertSame('00:00:00', $hoje->format('H:i:s'));
        $this->assertInstanceOf(CarbonImmutable::class, $hoje);
    }

    public function test_hoje_respeita_o_fuso_escolhido_pela_escola(): void
    {
        $this->congelar('2026-10-10 23:30:00');
        Estabelecimento::current()->update(['fuso_horario' => 'UTC']);

        $this->assertSame('2026-10-10', $this->relogio()->hoje()->toDateString());
    }

    public function test_agora_e_o_mesmo_instante_com_o_fuso_aplicado(): void
    {
        $this->congelar('2026-10-10 23:30:00');

        $agora = $this->relogio()->agora();

        $this->assertSame('Africa/Luanda', $agora->getTimezone()->getName());
        $this->assertSame('2026-10-11 00:30:00', $agora->format('Y-m-d H:i:s'));
        $this->assertSame(Carbon::now()->getTimestamp(), $agora->getTimestamp());
    }

    public function test_sem_tenant_usa_o_fuso_por_omissao(): void
    {
        $this->congelar('2026-10-10 23:30:00');
        app(TenantContext::class)->limpar();

        $this->assertSame('2026-10-11', $this->relogio()->hoje()->toDateString());
        $this->assertSame('Africa/Luanda', $this->relogio()->fuso());
    }

    public function test_o_fuso_do_tenant_b_nao_afecta_o_tenant_a(): void
    {
        $this->congelar('2026-10-10 23:30:00');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => Estabelecimento::current()->update(['fuso_horario' => 'UTC']));

        $this->assertSame('2026-10-11', $this->relogio()->hoje()->toDateString());
        $this->assertSame('2026-10-10', $this->noTenant($outro, fn () => $this->relogio()->hoje()->toDateString()));
        $this->assertSame('2026-10-11', $this->relogio()->hoje()->toDateString());
    }

    public function test_o_fuso_e_lido_uma_so_vez_por_pedido(): void
    {
        app(TenantContext::class)->definir($this->tenant->paraTenantAtual());
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->relogio()->hoje();
        $this->relogio()->agora();
        $this->relogio()->hoje();

        $consultas = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'estabelecimentos'));

        $this->assertCount(1, $consultas);
    }

    public function test_a_cache_e_invalidada_quando_o_estabelecimento_e_gravado(): void
    {
        $this->congelar('2026-10-10 23:30:00');
        $this->assertSame('2026-10-11', $this->relogio()->hoje()->toDateString());

        // Outra instância, sem passar por Estabelecimento::current().
        Estabelecimento::query()->firstOrFail()->update(['fuso_horario' => 'UTC']);

        $this->assertSame('2026-10-10', $this->relogio()->hoje()->toDateString());
    }

    public function test_so_o_fuso_hoje_nao_altera_a_zona_da_aplicacao(): void
    {
        $this->relogio()->hoje();

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('UTC', Carbon::now()->getTimezone()->getName());
    }
}
