<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaginasDeErroTest extends TestCase
{
    public function test_cada_codigo_tem_a_sua_pagina_com_o_mosi(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $codigo) {
            $html = view("errors.{$codigo}")->render();

            $this->assertStringContainsString("Erro {$codigo}", $html);
            $this->assertMatchesRegularExpression('#/images/mosi/mosi-(erro|a-pensar)\.svg#', $html);
            $this->assertFileExists(public_path('images/mosi/' . (str_contains($html, 'mosi-erro') ? 'mosi-erro' : 'mosi-a-pensar') . '.svg'));
        }
    }

    public function test_as_imagens_usadas_nas_paginas_e_nos_estados_vazios_existem_em_public(): void
    {
        foreach (['mosi-erro', 'mosi-a-pensar', 'mosi-sem-dados', 'mosi-sucesso', 'mosi-parabens', 'mosi-ola'] as $nome) {
            $this->assertFileExists(public_path("images/mosi/{$nome}.svg"));
        }
    }
}
