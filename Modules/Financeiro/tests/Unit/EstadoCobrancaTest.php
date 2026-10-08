<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\EstadoCobranca;
use PHPUnit\Framework\TestCase;

class EstadoCobrancaTest extends TestCase
{
    public function test_so_cinco_estados_sao_persistidos(): void
    {
        $persistidos = array_filter(EstadoCobranca::cases(), fn (EstadoCobranca $e) => $e->persistido());

        $this->assertEqualsCanonicalizing(
            [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA, EstadoCobranca::PAGA, EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA],
            array_values($persistidos),
        );
    }

    public function test_pendente_e_em_atraso_sao_derivados(): void
    {
        $this->assertFalse(EstadoCobranca::PENDENTE->persistido());
        $this->assertFalse(EstadoCobranca::EM_ATRASO->persistido());
    }

    public function test_transicoes_permitidas(): void
    {
        $this->assertEqualsCanonicalizing(
            [EstadoCobranca::PARCIALMENTE_PAGA, EstadoCobranca::PAGA, EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA],
            EstadoCobranca::EM_ABERTO->transicoesPermitidas(),
        );
        $this->assertEqualsCanonicalizing(
            [EstadoCobranca::EM_ABERTO, EstadoCobranca::PAGA],
            EstadoCobranca::PARCIALMENTE_PAGA->transicoesPermitidas(),
        );
        $this->assertEqualsCanonicalizing(
            [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA],
            EstadoCobranca::PAGA->transicoesPermitidas(),
        );
    }

    public function test_cancelada_e_anulada_sao_terminais(): void
    {
        $this->assertSame([], EstadoCobranca::CANCELADA->transicoesPermitidas());
        $this->assertSame([], EstadoCobranca::ANULADA->transicoesPermitidas());
    }

    public function test_paga_nao_pode_ser_cancelada_nem_anulada_directamente(): void
    {
        $this->assertFalse(EstadoCobranca::PAGA->podeTransitarPara(EstadoCobranca::CANCELADA));
        $this->assertFalse(EstadoCobranca::PAGA->podeTransitarPara(EstadoCobranca::ANULADA));
        $this->assertFalse(EstadoCobranca::PARCIALMENTE_PAGA->podeTransitarPara(EstadoCobranca::CANCELADA));
    }

    public function test_estados_derivados_nao_tem_transicoes(): void
    {
        $this->assertSame([], EstadoCobranca::PENDENTE->transicoesPermitidas());
        $this->assertSame([], EstadoCobranca::EM_ATRASO->transicoesPermitidas());
    }

    public function test_labels(): void
    {
        $this->assertSame('Em Aberto', EstadoCobranca::EM_ABERTO->label());
        $this->assertSame('Parcialmente Paga', EstadoCobranca::PARCIALMENTE_PAGA->label());
        $this->assertSame('Em Atraso', EstadoCobranca::EM_ATRASO->label());
    }
}
