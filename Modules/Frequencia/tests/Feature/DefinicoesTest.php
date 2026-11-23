<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Frequencia\Actions\GuardarDefinicoesAction;
use Modules\Frequencia\Models\FrequenciaDefinicoes;
use Modules\Frequencia\Services\JanelaDeCorreccaoService;
use Tests\TestCase;

class DefinicoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_janela_por_omissao_e_7_dias_com_limites_inclusivos(): void
    {
        $janela = app(JanelaDeCorreccaoService::class);

        $this->assertSame(7, $janela->dias());
        $this->assertTrue($janela->dentroDaJanela(Carbon::parse('2026-03-03')));
        $this->assertFalse($janela->dentroDaJanela(Carbon::parse('2026-03-02')));
        $this->assertTrue($janela->dentroDaJanela(Carbon::parse('2026-03-10')));
    }

    public function test_guardar_actualiza_a_unica_linha_do_tenant(): void
    {
        $acao = app(GuardarDefinicoesAction::class);

        $acao->executar(3);
        $acao->executar(14);

        $this->assertSame(1, FrequenciaDefinicoes::count());
        $this->assertSame(14, app(JanelaDeCorreccaoService::class)->dias());
    }

    public function test_limites_1_a_60(): void
    {
        foreach ([0, 61, -5] as $invalido) {
            try {
                app(GuardarDefinicoesAction::class)->executar($invalido);
                $this->fail("Esperava recusa para {$invalido}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('janela_correccao_dias', $e->errors());
            }
        }

        $this->assertSame(60, app(GuardarDefinicoesAction::class)->executar(60)->janela_correccao_dias);
        $this->assertSame(1, app(GuardarDefinicoesAction::class)->executar(1)->janela_correccao_dias);
    }
}
