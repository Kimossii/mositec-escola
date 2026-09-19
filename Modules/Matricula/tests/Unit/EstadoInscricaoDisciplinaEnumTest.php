<?php

namespace Modules\Matricula\Tests\Unit;

use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Tests\TestCase;

class EstadoInscricaoDisciplinaEnumTest extends TestCase
{
    public function test_inscrita_pode_transitar_para_os_tres_terminais(): void
    {
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::INSCRITA->podeTransitarPara(EstadoInscricaoDisciplinaEnum::CONCLUIDA));
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::INSCRITA->podeTransitarPara(EstadoInscricaoDisciplinaEnum::REPROVADA));
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::INSCRITA->podeTransitarPara(EstadoInscricaoDisciplinaEnum::DESISTIDA));
    }

    public function test_estados_terminais_nao_transitam(): void
    {
        $this->assertFalse(EstadoInscricaoDisciplinaEnum::CONCLUIDA->podeTransitarPara(EstadoInscricaoDisciplinaEnum::INSCRITA));
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::CONCLUIDA->eTerminal());
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::REPROVADA->eTerminal());
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::DESISTIDA->eTerminal());
        $this->assertFalse(EstadoInscricaoDisciplinaEnum::INSCRITA->eTerminal());
    }
}
