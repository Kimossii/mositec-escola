<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\EstadoMulta;
use PHPUnit\Framework\TestCase;

class EstadoMultaTest extends TestCase
{
    public function test_valores_e_rotulos_sao_os_do_spec(): void
    {
        $this->assertSame(1, EstadoMulta::ACTIVA->value);
        $this->assertSame(2, EstadoMulta::REDUZIDA->value);
        $this->assertSame(3, EstadoMulta::ISENTA->value);
        $this->assertSame(4, EstadoMulta::ANULADA->value);

        $this->assertSame('Activa', EstadoMulta::ACTIVA->label());
        $this->assertSame('Reduzida', EstadoMulta::REDUZIDA->label());
        $this->assertSame('Isenta', EstadoMulta::ISENTA->label());
        $this->assertSame('Anulada', EstadoMulta::ANULADA->label());
    }

    public function test_as_opcoes_cobrem_todos_os_estados(): void
    {
        $this->assertSame([
            ['value' => 1, 'label' => 'Activa'],
            ['value' => 2, 'label' => 'Reduzida'],
            ['value' => 3, 'label' => 'Isenta'],
            ['value' => 4, 'label' => 'Anulada'],
        ], EstadoMulta::opcoes());
    }
}
