<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use PHPUnit\Framework\TestCase;

class EstadosPagamentoTest extends TestCase
{
    public function test_estado_do_pagamento(): void
    {
        $this->assertSame([1, 2], array_map(fn (EstadoPagamento $e) => $e->value, EstadoPagamento::cases()));
        $this->assertSame('Confirmado', EstadoPagamento::CONFIRMADO->label());
        $this->assertSame('Anulado', EstadoPagamento::ANULADO->label());
        $this->assertSame([['value' => 1, 'label' => 'Confirmado'], ['value' => 2, 'label' => 'Anulado']], EstadoPagamento::opcoes());
    }

    public function test_estados_do_credito_e_da_utilizacao(): void
    {
        $this->assertSame(['Disponível', 'Esgotado', 'Anulado'], array_map(fn (EstadoCredito $e) => $e->label(), EstadoCredito::cases()));
        $this->assertSame([1, 2, 3], array_map(fn (EstadoCredito $e) => $e->value, EstadoCredito::cases()));
        $this->assertSame([['value' => 1, 'label' => 'Activa'], ['value' => 2, 'label' => 'Anulada']], EstadoUtilizacaoCredito::opcoes());
    }
}
