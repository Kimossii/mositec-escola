<?php

namespace Modules\AnoLectivo\Tests\Unit;

use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_estado_ano_lectivo_valores_e_labels(): void
    {
        $this->assertSame(0, EstadoAnoLectivo::PLANEADO->value);
        $this->assertSame(1, EstadoAnoLectivo::ATIVO->value);
        $this->assertSame(2, EstadoAnoLectivo::ENCERRADO->value);
        $this->assertSame('Planeado', EstadoAnoLectivo::PLANEADO->label());
        $this->assertSame('Activo', EstadoAnoLectivo::ATIVO->label());
        $this->assertSame('Encerrado', EstadoAnoLectivo::ENCERRADO->label());
    }

    public function test_tipo_periodo_valores_e_labels(): void
    {
        $this->assertSame(0, TipoPeriodo::TRIMESTRE->value);
        $this->assertSame(1, TipoPeriodo::SEMESTRE->value);
        $this->assertSame(2, TipoPeriodo::OUTRO->value);
        $this->assertSame('Trimestre', TipoPeriodo::TRIMESTRE->label());
    }

    public function test_tipo_evento_calendario_valores_e_labels(): void
    {
        $this->assertSame(0, TipoEventoCalendario::AULA->value);
        $this->assertSame(7, TipoEventoCalendario::OUTRO->value);
        $this->assertSame('Feriado', TipoEventoCalendario::FERIADO->label());
    }
}
