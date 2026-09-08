<?php

namespace Modules\Infraestrutura\Tests\Unit;

use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_tipo_sala_valores_e_labels(): void
    {
        $this->assertSame(0, TipoSala::SALA_AULA->value);
        $this->assertSame(1, TipoSala::LABORATORIO->value);
        $this->assertSame(2, TipoSala::BIBLIOTECA->value);
        $this->assertSame(3, TipoSala::AUDITORIO->value);
        $this->assertSame(4, TipoSala::GINASIO->value);
        $this->assertSame(5, TipoSala::SALA_PROFESSORES->value);
        $this->assertSame(6, TipoSala::GABINETE_ADMINISTRATIVO->value);
        $this->assertSame(7, TipoSala::OUTRO->value);

        $this->assertSame('Sala de Aula', TipoSala::SALA_AULA->label());
        $this->assertSame('Laboratório', TipoSala::LABORATORIO->label());
        $this->assertSame('Biblioteca', TipoSala::BIBLIOTECA->label());
        $this->assertSame('Auditório', TipoSala::AUDITORIO->label());
        $this->assertSame('Ginásio', TipoSala::GINASIO->label());
        $this->assertSame('Sala de Professores', TipoSala::SALA_PROFESSORES->label());
        $this->assertSame('Gabinete Administrativo', TipoSala::GABINETE_ADMINISTRATIVO->label());
        $this->assertSame('Outro', TipoSala::OUTRO->label());
    }

    public function test_estado_sala_valores_e_labels(): void
    {
        $this->assertSame(0, EstadoSala::ATIVA->value);
        $this->assertSame(1, EstadoSala::MANUTENCAO->value);
        $this->assertSame(2, EstadoSala::INATIVA->value);

        $this->assertSame('Ativa', EstadoSala::ATIVA->label());
        $this->assertSame('Em Manutenção', EstadoSala::MANUTENCAO->label());
        $this->assertSame('Inativa', EstadoSala::INATIVA->label());
    }
}
