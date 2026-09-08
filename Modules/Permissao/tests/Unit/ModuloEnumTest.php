<?php

namespace Modules\Permissao\Tests\Unit;

use Modules\Permissao\Enums\Modulo;
use Tests\TestCase;

class ModuloEnumTest extends TestCase
{
    public function test_slug_e_fromslug_fazem_round_trip(): void
    {
        foreach (Modulo::cases() as $modulo) {
            $this->assertSame($modulo, Modulo::fromSlug($modulo->slug()));
        }
    }

    public function test_fromslug_devolve_null_para_slug_desconhecido(): void
    {
        $this->assertNull(Modulo::fromSlug('inexistente'));
    }

    public function test_slugs_especificos(): void
    {
        $this->assertSame('horario', Modulo::HORARIO->slug());
        $this->assertSame('turmas', Modulo::TURMAS->slug());
        $this->assertSame('ano-lectivo', Modulo::ANO_LECTIVO->slug());
        $this->assertSame('estabelecimento', Modulo::ESTABELECIMENTO->slug());
    }

    public function test_tryfrom_nativo_devolve_null_para_int_desconhecido(): void
    {
        $this->assertNull(Modulo::tryFrom(999));
        $this->assertSame(Modulo::HORARIO, Modulo::tryFrom(11));
    }
}
