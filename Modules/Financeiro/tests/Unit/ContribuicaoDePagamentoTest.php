<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Financeiro\Support\ContribuicaoDePagamento;
use Modules\Financeiro\Support\Dinheiro;
use PHPUnit\Framework\TestCase;

class ContribuicaoDePagamentoTest extends TestCase
{
    public function test_guarda_valor_e_data(): void
    {
        $contribuicao = new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(1_000), CarbonImmutable::parse('2026-09-05'));

        $this->assertSame(1_000, $contribuicao->valor->unidadesMenores());
        $this->assertSame('2026-09-05', $contribuicao->data->toDateString());
    }

    public function test_contribuicao_de_zero_e_recusada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Uma contribuição para o valor pago tem de ser maior que zero.');

        new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(0), CarbonImmutable::parse('2026-09-05'));
    }
}
