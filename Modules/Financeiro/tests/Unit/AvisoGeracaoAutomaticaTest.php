<?php

namespace Modules\Financeiro\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P3: a geração automática segue o spec §7.2 (desde o mês da matrícula, incluindo meses já passados, que
 * nascem Em Atraso). Junto do interruptor, Regras de Cobrança avisa e recomenda pré-visualizar e gerar
 * manualmente antes de ligar. Lê o ficheiro sem o executar (não há testes de frontend); ignora comentários.
 */
class AvisoGeracaoAutomaticaTest extends TestCase
{
    public function test_o_interruptor_tem_o_aviso_logo_a_seguir_e_no_mesmo_cartao(): void
    {
        $vue = preg_replace('/<!--.*?-->/s', '', file_get_contents(dirname(__DIR__, 2) . '/resources/js/Pages/RegrasCobranca/Edit.vue'));

        $interruptor = strpos($vue, 'id="gerar_automaticamente"');
        $aviso = strpos($vue, 'data-aviso="geracao-automatica"');
        $cartaoSeguinte = strpos($vue, 'Negociação de dívidas');

        $this->assertNotFalse($interruptor);
        $this->assertNotFalse($aviso, 'Falta o aviso junto do interruptor «Gerar cobranças automaticamente».');
        $this->assertTrue($interruptor < $aviso && $aviso < $cartaoSeguinte, 'O aviso fica logo a seguir ao interruptor, no cartão «Pagamentos e geração».');

        $texto = substr($vue, $aviso, 900);
        $this->assertStringContainsString('desde o mês da matrícula', $texto);
        $this->assertStringContainsString('Em Atraso', $texto);
        $this->assertStringContainsString('pré-visualizar', $texto);
        $this->assertStringContainsString('Financeiro → Propinas', $texto);
    }
}
