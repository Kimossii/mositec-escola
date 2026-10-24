<?php

namespace Modules\Financeiro\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Não há testes de frontend: lê os ficheiros Vue (sem comentários) e prova as decisões que importam — o botão de
 * cancelar depende só do que o servidor decidiu (D13), o cancelamento vai pela rota PATCH com motivo, e a lista
 * liga ao detalhe.
 */
class DetalhePropinaVueTest extends TestCase
{
    private function vue(string $relativo): string
    {
        return preg_replace('/<!--.*?-->/s', '', file_get_contents(dirname(__DIR__, 2) . '/resources/js/' . $relativo));
    }

    public function test_o_botao_de_cancelar_so_aparece_quando_o_servidor_o_permite(): void
    {
        $show = $this->vue('Pages/Propinas/Show.vue');

        $this->assertStringContainsString('podeCancelar: { type: Boolean, required: true }', $show);
        $this->assertSame(1, preg_match('/<button[^>]*v-if="podeCancelar"[^>]*>\s*Cancelar propina/s', $show), 'O botão depende só de podeCancelar.');
        $this->assertStringContainsString("router.patch(`/financeiro/propinas/\${props.propina.id}/cancelar`, { motivo }", $show);
        $this->assertStringContainsString('<CancelarPropinaModal', $show);
    }

    public function test_o_modal_exige_motivo_com_limites(): void
    {
        $modal = $this->vue('Components/Propinas/CancelarPropinaModal.vue');

        $this->assertStringContainsString('maxlength="500"', $modal);
        $this->assertStringContainsString('motivo.value.trim().length < 5', $modal);
        $this->assertStringContainsString('errors.motivo', $modal);
        $this->assertStringContainsString('errors.propina', $modal);
    }

    public function test_a_lista_liga_ao_detalhe(): void
    {
        $this->assertStringContainsString(':href="`${BASE}/${propina.id}`"', $this->vue('Pages/Propinas/Index.vue'));
    }

    public function test_a_pre_visualizacao_assinala_um_periodo_cancelado_que_volta_a_ser_cobrado_o2(): void
    {
        $resultado = $this->vue('Components/Propinas/ResultadoGeracao.vue');

        $this->assertStringContainsString('v-if="propina.regenera_cancelada"', $resultado);
        $this->assertStringContainsString('{{ propina.regenera_cancelada }}', $resultado);
    }
}
