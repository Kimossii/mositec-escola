<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use PHPUnit\Framework\TestCase;

class OrigemGeracaoPropinaTest extends TestCase
{
    public function test_valores_e_rotulos(): void
    {
        $this->assertSame(
            [1 => 'Matrícula', 2 => 'Comando automático', 3 => 'Manual', 4 => 'Retroactiva'],
            array_combine(
                array_map(fn (OrigemGeracaoPropina $o) => $o->value, OrigemGeracaoPropina::cases()),
                array_map(fn (OrigemGeracaoPropina $o) => $o->label(), OrigemGeracaoPropina::cases()),
            ),
        );
    }

    public function test_so_a_geracao_retroactiva_exige_motivo(): void
    {
        foreach (OrigemGeracaoPropina::cases() as $origem) {
            $this->assertSame($origem === OrigemGeracaoPropina::RETROACTIVA, $origem->exigeMotivo(), $origem->name);
        }
    }
}
