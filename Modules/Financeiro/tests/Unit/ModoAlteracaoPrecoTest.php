<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use PHPUnit\Framework\TestCase;

class ModoAlteracaoPrecoTest extends TestCase
{
    public function test_valores_e_rotulos_sao_os_do_spec(): void
    {
        $this->assertSame(1, ModoAlteracaoPreco::APLICAR_GERADAS->value);
        $this->assertSame(2, ModoAlteracaoPreco::MANTER_GERADAS->value);
        $this->assertSame('Aplicar às propinas já geradas', ModoAlteracaoPreco::APLICAR_GERADAS->label());
        $this->assertSame('Manter as já geradas', ModoAlteracaoPreco::MANTER_GERADAS->label());
    }

    public function test_as_opcoes_nao_marcam_nenhuma_por_omissao(): void
    {
        $this->assertSame([
            ['value' => 1, 'label' => 'Aplicar às propinas já geradas'],
            ['value' => 2, 'label' => 'Manter as já geradas'],
        ], ModoAlteracaoPreco::opcoes());
    }
}
