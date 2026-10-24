<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Contracts\FonteDePrecos;
use Modules\Financeiro\Support\FontesDePrecos;
use Tests\TestCase;

class FontesDePrecosTest extends TestCase
{
    use RefreshDatabase;

    private function fonte(bool $resposta): FonteDePrecos
    {
        return new class($resposta) implements FonteDePrecos {
            public function __construct(private bool $resposta)
            {
            }

            public function existemPrecos(): bool
            {
                return $this->resposta;
            }
        };
    }

    private function registar(string $nome, FonteDePrecos $fonte): void
    {
        $this->app->instance($nome, $fonte);
        $this->app->tag([$nome], FontesDePrecos::ETIQUETA);
    }

    public function test_sem_fontes_nao_ha_precos(): void
    {
        $this->assertFalse(app(FontesDePrecos::class)->existem());
    }

    public function test_todas_negam_nao_ha_precos(): void
    {
        $this->registar('fonte.a', $this->fonte(false));
        $this->registar('fonte.b', $this->fonte(false));

        $this->assertFalse(app(FontesDePrecos::class)->existem());
    }

    public function test_basta_uma_afirmar_para_haver_precos(): void
    {
        $this->registar('fonte.a', $this->fonte(false));
        $this->registar('fonte.b', $this->fonte(true));

        $this->assertTrue(app(FontesDePrecos::class)->existem());
    }
}
