<?php

namespace Modules\Estabelecimento\Tests\Unit;

use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Tests\TestCase;

class TipoEnsinoEnumTest extends TestCase
{
    public function test_labels(): void
    {
        $this->assertSame('Ensino Geral', TipoEnsinoEnum::GERAL->label());
        $this->assertSame('Ensino Técnico', TipoEnsinoEnum::TECNICO->label());
        $this->assertSame('Ensino Universitário', TipoEnsinoEnum::UNIVERSITARIO->label());
    }

    public function test_from_valores_validos(): void
    {
        $this->assertSame(TipoEnsinoEnum::GERAL, TipoEnsinoEnum::from(1));
        $this->assertSame(TipoEnsinoEnum::TECNICO, TipoEnsinoEnum::from(2));
        $this->assertSame(TipoEnsinoEnum::UNIVERSITARIO, TipoEnsinoEnum::from(3));
    }

    public function test_valor_invalido_lanca_erro(): void
    {
        $this->expectException(\ValueError::class);
        TipoEnsinoEnum::from(99);
    }
}
