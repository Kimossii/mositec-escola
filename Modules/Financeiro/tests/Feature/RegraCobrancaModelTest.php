<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Models\RegraCobranca;
use Tests\TestCase;

class RegraCobrancaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_do_tenant_cria_com_os_defaults(): void
    {
        $regra = RegraCobranca::doTenant();

        $this->assertSame($this->tenant->id, $regra->tenant_id);
        $this->assertSame(10, $regra->dia_vencimento);
        $this->assertSame(5, $regra->dias_tolerancia);
        $this->assertFalse($regra->permite_pagamento_parcial);
        $this->assertFalse($regra->permite_pagamento_antecipado);
        $this->assertFalse($regra->gerar_automaticamente);
        $this->assertFalse($regra->permite_negociacao);
        $this->assertSame(0, $regra->desconto_maximo_negociacao);
    }

    public function test_do_tenant_e_idempotente(): void
    {
        $primeira = RegraCobranca::doTenant();
        $segunda = RegraCobranca::doTenant();

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(1, RegraCobranca::count());
    }

    public function test_a_bd_impede_uma_segunda_regra_no_mesmo_tenant(): void
    {
        RegraCobranca::doTenant();

        $this->expectException(QueryException::class);

        RegraCobranca::create(RegraCobranca::DEFAULTS);
    }

    public function test_negociacao_desligada_forca_desconto_zero(): void
    {
        $regra = RegraCobranca::doTenant();

        $regra->update(['permite_negociacao' => false, 'desconto_maximo_negociacao' => 50]);

        $this->assertSame(0, $regra->fresh()->desconto_maximo_negociacao);
    }

    public function test_negociacao_ligada_mantem_o_desconto(): void
    {
        $regra = RegraCobranca::doTenant();

        $regra->update(['permite_negociacao' => true, 'desconto_maximo_negociacao' => 50]);

        $this->assertSame(50, $regra->fresh()->desconto_maximo_negociacao);
    }

    public function test_cada_tenant_tem_a_sua_regra(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        RegraCobranca::doTenant()->update(['dia_vencimento' => 20]);
        $doOutro = $this->noTenant($outro, fn () => RegraCobranca::doTenant());

        $this->assertSame(10, $doOutro->dia_vencimento);
        $this->assertSame($outro->id, $doOutro->tenant_id);
        $this->assertSame(20, RegraCobranca::doTenant()->dia_vencimento);
    }
}
