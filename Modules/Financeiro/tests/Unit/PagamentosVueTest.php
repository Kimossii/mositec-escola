<?php

namespace Modules\Financeiro\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Não há testes de frontend: lê os ficheiros Vue (sem comentários) e prova as decisões que importam (D19): as acções
 * dependem do que o servidor decidiu, as rotas certas, e o registo envia a distribuição que o resumo mostrou.
 */
class PagamentosVueTest extends TestCase
{
    private function vue(string $relativo): string
    {
        return preg_replace('/<!--.*?-->/s', '', file_get_contents(dirname(__DIR__, 2) . '/resources/js/' . $relativo));
    }

    public function test_a_lista_so_mostra_registar_com_permissao_e_liga_ao_detalhe(): void
    {
        $index = $this->vue('Pages/Pagamentos/Index.vue');

        $this->assertStringContainsString('podeRegistar: { type: Boolean, required: true }', $index);
        $this->assertSame(1, preg_match('/<Link[^>]*v-if="podeRegistar"[^>]*href="\/financeiro\/pagamentos\/novo"/s', $index));
        $this->assertStringContainsString(':href="`/financeiro/pagamentos/${pagamento.id}`"', $index);
    }

    public function test_o_detalhe_so_deixa_anular_quando_o_servidor_permite(): void
    {
        $show = $this->vue('Pages/Pagamentos/Show.vue');

        $this->assertStringContainsString('podeAnular: { type: Boolean, required: true }', $show);
        $this->assertSame(1, preg_match('/<button[^>]*v-if="podeAnular"[^>]*>\s*Anular pagamento/s', $show));
        $this->assertStringContainsString("router.patch(`/financeiro/pagamentos/\${props.pagamento.id}/anular`, { motivo }", $show);
        $this->assertStringContainsString("router.patch(`/financeiro/creditos/utilizacoes/\${utilizacaoAnular.value.id}/anular`, { motivo }", $show);
        $this->assertStringContainsString('pagamento.anulacao_bloqueada', $show);
    }

    public function test_a_anulacao_lista_as_utilizacoes_de_credito_do_mesmo_acto(): void
    {
        $show = $this->vue('Pages/Pagamentos/Show.vue');
        $modal = $this->vue('Components/Pagamentos/AnularRegistoModal.vue');

        $this->assertStringContainsString('pagamento.utilizacoes_combinadas', $show);
        $this->assertStringContainsString(':itens="itensDaAnulacao"', $show);
        $this->assertStringContainsString('itens: { type: Array', $modal);
        $this->assertStringContainsString('v-for="(item, indice) in itens"', $modal);
        $this->assertStringContainsString('page.props.flash?.warning', $show);
    }

    public function test_o_modal_de_anulacao_exige_motivo_com_limites(): void
    {
        $modal = $this->vue('Components/Pagamentos/AnularRegistoModal.vue');

        $this->assertStringContainsString('maxlength="500"', $modal);
        $this->assertStringContainsString('motivo.value.trim().length < 5', $modal);
        $this->assertStringContainsString('errors[campoErro]', $modal);
        $this->assertStringContainsString('errors.motivo', $modal);
    }

    public function test_as_anulacoes_bloqueiam_o_duplo_submit(): void
    {
        $show = $this->vue('Pages/Pagamentos/Show.vue');

        $this->assertSame(2, substr_count($show, 'if (processing.value) return;'), 'Anular pagamento e anular utilização.');
        $this->assertStringContainsString(':disabled="processing || invalido"', $this->vue('Components/Pagamentos/AnularRegistoModal.vue'));
    }

    public function test_o_registo_confirma_a_distribuicao_que_o_resumo_mostrou(): void
    {
        $create = $this->vue('Pages/Pagamentos/Create.vue');

        $this->assertStringContainsString("axios.post(`/financeiro/pagamentos/matriculas/\${matricula.value.id}/pre-visualizar`, pedidoDaEdicao())", $create);
        $this->assertStringContainsString('unidadesMenoresParaDecimal(l.credito + l.dinheiro + l.multa_credito + l.multa_dinheiro, props.moeda)', $create);
        $this->assertStringContainsString("router.post(`/financeiro/pagamentos/matriculas/\${matricula.value.id}`, { ...base(), modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA, distribuicao }", $create);
        $this->assertStringContainsString(':max="hoje"', $create);
    }

    public function test_o_resumo_avisa_quando_tudo_vai_para_credito(): void
    {
        $create = $this->vue('Pages/Pagamentos/Create.vue');

        $this->assertStringContainsString('Nada a distribuir: o valor fica como crédito do aluno', $create);
        $this->assertStringContainsString('v-model="confirmaTudoCredito"', $create);
        $this->assertStringContainsString('(!tudoParaCredito.value || confirmaTudoCredito.value)', $create);
        $this->assertSame(1, preg_match('/<button[^>]*:disabled="!podeConfirmar"[^>]*>\s*\{\{ processing \? \'A registar…\' : \'Confirmar pagamento\' \}\}/s', $create));
    }

    public function test_o_registo_limita_a_data_e_mostra_os_avisos_sem_bloquear(): void
    {
        $create = $this->vue('Pages/Pagamentos/Create.vue');

        $this->assertStringContainsString(':min="situacao?.matricula?.data_matricula"', $create);
        $this->assertStringContainsString('avisos.value = data.avisos ?? [];', $create);
        $this->assertStringContainsString('v-for="aviso in avisos"', $create);
        $this->assertStringNotContainsString('avisos.length', $create, 'Os avisos nunca desactivam a confirmação (O1).');
    }

    public function test_nenhum_modo_nem_metodo_vem_preseleccionado(): void
    {
        $create = $this->vue('Pages/Pagamentos/Create.vue');
        $aplicar = $this->vue('Components/Pagamentos/AplicarCreditoModal.vue');

        $this->assertStringContainsString('const modo = ref(null);', $create);
        $this->assertStringContainsString("metodo_pagamento_id: '',", $create);
        $this->assertStringNotContainsString('props.metodos[0]', $create);
        $this->assertStringContainsString(':disabled="aPrever || !modo"', $create);
        $this->assertStringNotContainsString('props.matriculas[0]', $aplicar);
        $this->assertStringNotContainsString('unidadesMenoresParaDecimal(props.saldoDisponivel', $aplicar);
    }

    public function test_o_registo_bloqueia_o_duplo_submit_e_le_erros_so_de_errors(): void
    {
        $create = $this->vue('Pages/Pagamentos/Create.vue');

        $this->assertStringContainsString('if (!podeConfirmar.value) return;', $create);
        $this->assertStringContainsString('if (aPrever.value || !modo.value) return;', $create);
        $this->assertStringContainsString('erro.response.data.errors', $create);
        $this->assertStringNotContainsString('erro.response.data.message', $create);
    }

    public function test_creditos_do_aluno_aplicam_e_anulam_pelas_rotas_certas(): void
    {
        $aluno = $this->vue('Pages/Creditos/Aluno.vue');
        $modal = $this->vue('Components/Pagamentos/AplicarCreditoModal.vue');

        $this->assertStringContainsString('podeAplicar: { type: Boolean, required: true }', $aluno);
        $this->assertStringContainsString("router.patch(`/financeiro/creditos/utilizacoes/\${utilizacaoAnular.value.id}/anular`, { motivo }", $aluno);
        $this->assertStringContainsString("axios.post(`/financeiro/creditos/matriculas/\${matriculaId.value}/pre-visualizar`", $modal);
        $this->assertStringContainsString("router.post(`/financeiro/creditos/matriculas/\${matriculaId.value}/aplicar`", $modal);
        $this->assertStringContainsString('modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA', $modal);
        $this->assertStringContainsString('if (processing.value) return;', $modal);
    }

    public function test_o_detalhe_da_propina_lista_pagamentos_e_so_anula_quando_o_servidor_permite(): void
    {
        $show = $this->vue('Pages/Propinas/Show.vue');

        $this->assertStringContainsString('podeAnular: { type: Boolean, required: true }', $show);
        $this->assertSame(1, preg_match('/<button[^>]*v-if="podeAnular"[^>]*>\s*Anular propina/s', $show));
        $this->assertStringContainsString("router.patch(`/financeiro/propinas/\${props.propina.id}/anular`, { motivo }", $show);
        $this->assertStringContainsString('v-for="registo in propina.pagamentos"', $show);
        $this->assertStringNotContainsString('quando o módulo de Pagamentos estiver disponível', $show);
        $this->assertStringNotContainsString('com o módulo de Pagamentos', $this->vue('Components/Propinas/CancelarPropinaModal.vue'));
    }
}
