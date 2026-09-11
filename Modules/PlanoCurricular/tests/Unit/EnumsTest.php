<?php

namespace Modules\PlanoCurricular\Tests\Unit;

use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    public function test_componente_labels(): void
    {
        $this->assertSame('Geral', ComponentePlanoCurricular::GERAL->label());
        $this->assertSame('Técnica', ComponentePlanoCurricular::TECNICA->label());
        $this->assertSame('Prática', ComponentePlanoCurricular::PRATICA->label());
    }

    public function test_tipo_labels(): void
    {
        $this->assertSame('Normal', TipoDisciplinaPlano::NORMAL->label());
        $this->assertSame('Estágio', TipoDisciplinaPlano::ESTAGIO->label());
        $this->assertSame('Optativa', TipoDisciplinaPlano::OPTATIVA->label());
        $this->assertSame('Projecto', TipoDisciplinaPlano::PROJETO->label());
    }
}
