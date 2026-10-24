<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Database\Seeders\CambioPlataformaSeeder;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Services\CambioDoDia;
use Modules\Financeiro\Support\TaxaCambio;
use Tests\TestCase;

class CambioDoDiaTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): CambioDoDia
    {
        return app(CambioDoDia::class);
    }

    private function dia(string $data): CarbonImmutable
    {
        return CarbonImmutable::parse($data);
    }

    private function plataforma(string $data, string $taxa, string $moeda = 'AOA'): void
    {
        CambioPlataforma::create([
            'moeda_cotada' => $moeda, 'moeda_base' => 'USD', 'data' => $data,
            'taxa' => TaxaCambio::deDecimal($taxa)->micros(), 'fonte' => 'manual',
        ]);
    }

    private function daEscola(string $data, string $taxa, string $moeda = 'AOA'): void
    {
        Cambio::create([
            'moeda_cotada' => $moeda, 'moeda_base' => 'USD', 'data' => $data,
            'taxa' => TaxaCambio::deDecimal($taxa)->micros(),
        ]);
    }

    public function test_sem_nenhum_cambio_devolve_nulo_sem_erro(): void
    {
        $this->assertNull($this->servico()->para($this->dia('2026-10-05')));
        $this->assertNull($this->servico()->resolver($this->dia('2026-10-05')));
    }

    public function test_moeda_da_escola_em_usd_vale_um(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'USD']);

        $resolvido = $this->servico()->resolver($this->dia('2026-10-05'));

        $this->assertSame(1_000_000, $resolvido->taxa->micros());
        $this->assertSame('usd', $resolvido->origem);
        $this->assertNull($resolvido->data);
    }

    public function test_usa_o_padrao_da_plataforma_para_qualquer_data(): void
    {
        $this->seed(CambioPlataformaSeeder::class);

        $resolvido = $this->servico()->resolver($this->dia('2026-10-05'));

        $this->assertSame('910,00', $resolvido->taxa->formatar());
        $this->assertSame('plataforma', $resolvido->origem);
    }

    public function test_uma_taxa_posterior_nao_vale_para_tras(): void
    {
        $this->seed(CambioPlataformaSeeder::class);
        $this->plataforma('2026-10-01', '920');

        $this->assertSame('920,00', $this->servico()->para($this->dia('2026-10-05'))->formatar());
        $this->assertSame('920,00', $this->servico()->para($this->dia('2026-10-01'))->formatar());
        $this->assertSame('910,00', $this->servico()->para($this->dia('2026-09-30'))->formatar());
    }

    public function test_a_plataforma_so_conta_para_a_moeda_da_escola(): void
    {
        $this->plataforma('2026-10-01', '920', 'MZN');

        $this->assertNull($this->servico()->para($this->dia('2026-10-05')));
    }

    public function test_modo_manual_usa_so_a_tabela_da_escola(): void
    {
        $this->seed(CambioPlataformaSeeder::class);
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);

        $this->assertNull($this->servico()->para($this->dia('2026-10-05'))); // estrito: não cai na plataforma

        $this->daEscola('2026-10-02', '930');

        $resolvido = $this->servico()->resolver($this->dia('2026-10-05'));
        $this->assertSame('930,00', $resolvido->taxa->formatar());
        $this->assertSame('escola', $resolvido->origem);
        $this->assertSame('2026-10-02', $resolvido->data);
        $this->assertNull($this->servico()->para($this->dia('2026-10-01'))); // antes da primeira linha
    }

    public function test_modo_manual_ignora_linhas_de_outra_moeda(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);
        $this->daEscola('2026-10-02', '930', 'EUR');

        $this->assertNull($this->servico()->para($this->dia('2026-10-05')));
    }

    public function test_cambios_de_outra_escola_nao_sao_usados(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->daEscola('2026-10-02', '930'));

        $this->assertNull($this->servico()->para($this->dia('2026-10-05')));
    }

    public function test_a_bd_impede_dois_cambios_da_plataforma_no_mesmo_dia_para_a_mesma_moeda(): void
    {
        $this->plataforma('2026-10-01', '910');

        $this->expectException(QueryException::class);

        $this->plataforma('2026-10-01', '920');
    }

    public function test_plataforma_permite_o_mesmo_dia_noutra_moeda_e_outro_dia_na_mesma_moeda(): void
    {
        $this->plataforma('2026-10-01', '910');
        $this->plataforma('2026-10-01', '17', 'MZN');
        $this->plataforma('2026-10-02', '915');

        $this->assertSame(3, CambioPlataforma::count());
    }

    public function test_a_bd_impede_dois_cambios_da_escola_no_mesmo_dia_para_a_mesma_moeda(): void
    {
        $this->daEscola('2026-10-01', '910');

        $this->expectException(QueryException::class);

        $this->daEscola('2026-10-01', '920');
    }

    public function test_a_unicidade_do_cambio_da_escola_e_por_tenant(): void
    {
        $this->daEscola('2026-10-01', '910');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($outro, fn () => $this->daEscola('2026-10-01', '930'));

        $this->assertSame(1, Cambio::count());
        $this->assertSame(1, $this->noTenant($outro, fn () => Cambio::count()));
    }

    public function test_o_modo_manual_nao_altera_o_que_a_plataforma_diz_a_outras_escolas(): void
    {
        $this->seed(CambioPlataformaSeeder::class);
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $doOutro = $this->noTenant($outro, fn () => $this->servico()->para($this->dia('2026-10-05')));

        $this->assertSame('910,00', $doOutro->formatar());
    }
}
