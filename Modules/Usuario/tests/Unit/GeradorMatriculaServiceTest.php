<?php

namespace Modules\Usuario\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Usuario\Services\GeradorMatriculaService;
use Tests\TestCase;

class GeradorMatriculaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gera_matricula_no_formato_ano_sequencial(): void
    {
        $matricula = (new GeradorMatriculaService())->gerar();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $matricula);
        $this->assertStringStartsWith((string) now()->year, $matricula);
    }

    public function test_sequencial_incrementa_a_cada_chamada(): void
    {
        $servico = new GeradorMatriculaService();
        $ano = now()->year;

        $this->assertSame("{$ano}-0001", $servico->gerar());
        $this->assertSame("{$ano}-0002", $servico->gerar());
        $this->assertSame("{$ano}-0003", $servico->gerar());
    }
}
