<?php

namespace Modules\Estabelecimento\Tests\Unit;

use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Tests\TestCase;

class EtapaEnsinoEnumTest extends TestCase
{
    public function test_labels(): void
    {
        $this->assertSame('Creche', EtapaEnsinoEnum::CRECHE->label());
        $this->assertSame('Pré-Escolar', EtapaEnsinoEnum::PRE_ESCOLAR->label());
        $this->assertSame('Ensino Primário', EtapaEnsinoEnum::PRIMARIO->label());
        $this->assertSame('Ensino Secundário', EtapaEnsinoEnum::SECUNDARIO->label());
        $this->assertSame('Ensino Superior', EtapaEnsinoEnum::SUPERIOR->label());
    }

    public function test_from_valores_validos(): void
    {
        $this->assertSame(EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::from(1));
        $this->assertSame(EtapaEnsinoEnum::PRE_ESCOLAR, EtapaEnsinoEnum::from(2));
        $this->assertSame(EtapaEnsinoEnum::PRIMARIO, EtapaEnsinoEnum::from(3));
        $this->assertSame(EtapaEnsinoEnum::SECUNDARIO, EtapaEnsinoEnum::from(4));
        $this->assertSame(EtapaEnsinoEnum::SUPERIOR, EtapaEnsinoEnum::from(5));
    }

    public function test_valor_invalido_lanca_erro(): void
    {
        $this->expectException(\ValueError::class);
        EtapaEnsinoEnum::from(99);
    }

    public function test_exige_curso(): void
    {
        $this->assertFalse(EtapaEnsinoEnum::CRECHE->exigeCurso());
        $this->assertFalse(EtapaEnsinoEnum::PRE_ESCOLAR->exigeCurso());
        $this->assertFalse(EtapaEnsinoEnum::PRIMARIO->exigeCurso());
        $this->assertTrue(EtapaEnsinoEnum::SECUNDARIO->exigeCurso());
        $this->assertTrue(EtapaEnsinoEnum::SUPERIOR->exigeCurso());
    }
}
