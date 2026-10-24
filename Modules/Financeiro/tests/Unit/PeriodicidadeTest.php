<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\Periodicidade;
use PHPUnit\Framework\TestCase;

class PeriodicidadeTest extends TestCase
{
    public function test_valores_e_meses(): void
    {
        $this->assertSame(1, Periodicidade::MENSAL->meses());
        $this->assertSame(2, Periodicidade::BIMESTRAL->meses());
        $this->assertSame(3, Periodicidade::TRIMESTRAL->meses());
        $this->assertSame(6, Periodicidade::SEMESTRAL->meses());
        $this->assertSame(12, Periodicidade::ANUAL->meses());
        $this->assertNull(Periodicidade::OUTRA->meses());
        $this->assertSame(0, Periodicidade::OUTRA->value);
    }

    public function test_labels(): void
    {
        $this->assertSame('Mensal', Periodicidade::MENSAL->label());
        $this->assertSame('Bimestral', Periodicidade::BIMESTRAL->label());
        $this->assertSame('Trimestral', Periodicidade::TRIMESTRAL->label());
        $this->assertSame('Semestral', Periodicidade::SEMESTRAL->label());
        $this->assertSame('Anual', Periodicidade::ANUAL->label());
        $this->assertSame('Outra periodicidade', Periodicidade::OUTRA->label());
    }

    public function test_opcoes_tem_outra_no_fim(): void
    {
        $opcoes = Periodicidade::opcoes();

        $this->assertCount(6, $opcoes);
        $this->assertSame(['value' => 1, 'label' => 'Mensal'], $opcoes[0]);
        $this->assertSame(['value' => 0, 'label' => 'Outra periodicidade'], $opcoes[5]);
    }
}
