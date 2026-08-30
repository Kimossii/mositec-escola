<?php

namespace Modules\Core\Tests\Unit;

use Modules\Core\Enums\Estado;
use PHPUnit\Framework\TestCase;

class EstadoTest extends TestCase
{
    public function test_valores_sao_os_persistidos_atualmente(): void
    {
        $this->assertSame(0, Estado::INATIVO->value);
        $this->assertSame(1, Estado::ATIVO->value);
    }

    public function test_label_devolve_texto_legivel(): void
    {
        $this->assertSame('Inativo', Estado::INATIVO->label());
        $this->assertSame('Ativo', Estado::ATIVO->label());
    }

    public function test_from_resolve_a_partir_do_inteiro_persistido(): void
    {
        $this->assertSame(Estado::ATIVO, Estado::from(1));
        $this->assertSame(Estado::INATIVO, Estado::from(0));
    }
}
