<?php

namespace Modules\Financeiro\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Não há testes de frontend: lê os ficheiros Vue (sem comentários) e prova as decisões de F4 que vivem na UI — a
 * escolha nasce SEM opção marcada, a pré-visualização é obrigatória antes de confirmar, a confirmação leva a
 * impressão digital e o motivo, e uma impressão desactualizada refaz a pré-visualização.
 */
class AlterarPrecoPlanoVueTest extends TestCase
{
    private function vue(string $relativo): string
    {
        return preg_replace('/<!--.*?-->/s', '', file_get_contents(dirname(__DIR__, 2) . '/resources/js/' . $relativo));
    }

    public function test_a_escolha_nao_tem_opcao_pre_seleccionada_e_bloqueia_a_pre_visualizacao(): void
    {
        $modal = $this->vue('Components/PlanosPropina/AlterarPrecoPlanoModal.vue');

        $this->assertStringContainsString('modo: null,', $modal);
        $this->assertStringNotContainsString('checked', $modal);
        $this->assertStringContainsString('v-model="form.modo"', $modal);
        $this->assertStringContainsString('form.modo !== null', $modal);
        $this->assertStringContainsString(':disabled="!podePreVisualizar || aCalcular"', $modal);
        $this->assertStringContainsString("{ active: form.modo === modo.value }", $modal);
    }

    public function test_confirma_so_depois_da_pre_visualizacao_com_impressao_e_motivo(): void
    {
        $modal = $this->vue('Components/PlanosPropina/AlterarPrecoPlanoModal.vue');

        $this->assertStringContainsString('/alterar-preco/pre-visualizar`', $modal);
        $this->assertStringContainsString('impressao_digital: previa.value.impressao_digital', $modal);
        $this->assertStringContainsString('maxlength="500"', $modal);
        $this->assertStringContainsString('form.motivo.trim().length >= 5', $modal);
        $this->assertStringContainsString('{{ form.motivo.length }}/500', $modal);
        $this->assertStringContainsString('{{ previa.garantia_outros_anos }}', $modal);
        $this->assertStringContainsString('v-if="previa.exige_confirmacao_valores_diferentes"', $modal);
        $this->assertStringContainsString('v-model="form.confirmar_valores_diferentes"', $modal);
        $this->assertStringContainsString('previa.value !== null', $modal);
    }

    public function test_bloqueia_duplo_submit_e_mostra_erros_e_estados_vazios(): void
    {
        $modal = $this->vue('Components/PlanosPropina/AlterarPrecoPlanoModal.vue');

        $this->assertStringContainsString('if (!podeConfirmar.value || props.processing) return;', $modal);
        $this->assertStringContainsString(':disabled="!podeConfirmar || processing"', $modal);
        $this->assertStringContainsString("erro('plano')", $modal);
        $this->assertStringContainsString('errors.plano', $modal);
        $this->assertStringContainsString('v-else class="text-muted fs-7 mb-5">Nenhuma propina já gerada muda.', $modal);
        $this->assertStringContainsString('previa.reassociadas_sem_ajuste', $modal);
        $this->assertStringContainsString('bg-body-secondary', $modal);
    }

    public function test_uma_impressao_desactualizada_refaz_a_pre_visualizacao(): void
    {
        $modal = $this->vue('Components/PlanosPropina/AlterarPrecoPlanoModal.vue');

        $this->assertSame(1, preg_match('/watch\(\(\) => props\.errors\.impressao_digital,.*?preVisualizar\(\)/s', $modal));
        $this->assertSame(1, preg_match('/watch\(\(\) => props\.errors\.impressao_digital,.*?confirmar_valores_diferentes = false;.*?preVisualizar\(\)/s', $modal));
    }

    public function test_a_lista_de_planos_so_oferece_a_accao_com_propinas_de_ano_aberto_e_as_duas_permissoes(): void
    {
        $index = $this->vue('Pages/PlanosPropina/Index.vue');

        $this->assertStringContainsString('v-if="plano.tem_propinas && !plano.ano_lectivo_encerrado && can(\'propina.ajustar\') && can(\'plano-propina.editar\')"', $index);
        $this->assertStringContainsString('router.post(`${BASE}/${planoPreco.value.id}/alterar-preco`', $index);
        $this->assertStringContainsString('<AlterarPrecoPlanoModal', $index);
        $this->assertStringContainsString('modosAlteracaoPreco: { type: Array, required: true }', $index);
    }

    public function test_o_detalhe_lista_os_ajustes(): void
    {
        $show = $this->vue('Pages/Propinas/Show.vue');

        $this->assertStringContainsString('v-for="ajuste in propina.ajustes"', $show);
        $this->assertStringContainsString('{{ ajuste.motivo }}', $show);
    }
}
