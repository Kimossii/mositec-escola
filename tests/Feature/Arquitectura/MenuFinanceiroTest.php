<?php

namespace Tests\Feature\Arquitectura;

use PHPUnit\Framework\TestCase;

/**
 * Paridade header/sidebar do menu Financeiro (operação): o mesmo link, com a mesma permissão, nos dois
 * sítios (Q13 do plano de fases), e só "Propinas" e "Pagamentos" activos até as outras áreas existirem. Lê os ficheiros
 * sem os executar (não há testes de frontend no projecto); ignora o que está comentado.
 */
class MenuFinanceiroTest extends TestCase
{
    private function ficheiro(string $relativo): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/' . $relativo);
    }

    private function semComentariosHtml(string $codigo): string
    {
        return preg_replace('/<!--.*?-->/s', '', $codigo);
    }

    private function semComentariosJs(string $codigo): string
    {
        return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $codigo);
    }

    /** @return array{0: string, 1: string} template e script da sidebar */
    private function sidebar(): array
    {
        [$template, $script] = explode('<script setup>', $this->ficheiro('resources/js/Components/Layout/SidebarMenuWrapper.vue'), 2);

        return [$this->semComentariosHtml($template), $this->semComentariosJs($script)];
    }

    public function test_a_sidebar_mostra_a_seccao_financeiro_com_propinas_e_pagamentos(): void
    {
        [$template, $script] = $this->sidebar();

        $this->assertStringContainsString('<SidebarAccordion :item="financeiro" heading="Financeiro" />', $template);
        $this->assertSame(1, preg_match("#const financeiro = \{(.*?)\n\}#s", $script, $bloco), 'const financeiro tem de estar activo.');
        $this->assertStringContainsString("{ href: '/financeiro/propinas', title: 'Propinas', permissao: 'propina.ver' }", $bloco[1]);
        $this->assertStringContainsString("{ href: '/financeiro/pagamentos', title: 'Pagamentos', permissao: 'pagamento.ver' }", $bloco[1]);
        $this->assertSame(8, preg_match_all('/href:/', $bloco[1]), 'Propinas, Pagamentos, Cobranças, Dívidas, Recibos e Relatórios activos.');
    }

    public function test_o_header_tem_o_mesmo_link_com_a_mesma_permissao(): void
    {
        $composable = $this->semComentariosJs($this->ficheiro('resources/js/Composables/useFinanceiroMenu.js'));
        $header = $this->ficheiro('resources/js/Components/Layout/HeaderMenu.vue');

        $this->assertStringContainsString("{ href: '/financeiro/propinas', label: 'Propinas', permissao: 'propina.ver' }", $composable);
        $this->assertStringContainsString("{ href: '/financeiro/pagamentos', label: 'Pagamentos', permissao: 'pagamento.ver' }", $composable);
        $this->assertSame(8, preg_match_all('/href:/', $composable));
        $this->assertStringContainsString("import FinanceiroMenu from './menus/FinanceiroMenu.vue'", $header);
        $this->assertStringContainsString("import { useFinanceiroMenu } from '@/Composables/useFinanceiroMenu'", $header);
        $this->assertStringContainsString('<FinanceiroMenu />', $this->semComentariosHtml($header));
    }

    public function test_a_configuracao_financeira_continua_em_configuracoes(): void
    {
        [, $script] = $this->sidebar();

        $this->assertStringContainsString("href: '/financeiro/configuracao/planos-propina'", $script);
        $this->assertStringContainsString("href: '/financeiro/configuracao/planos-propina'", $this->ficheiro('resources/js/Composables/useConfiguracoesMenu.js'));
    }
}
