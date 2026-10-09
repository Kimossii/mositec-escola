<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Tests\TestCase;

class ReferenciasFinanceirasTest extends TestCase
{
    private function referencia(bool $resposta): ReferenciaFinanceira
    {
        return new class($resposta) implements ReferenciaFinanceira {
            public ?Model $recebido = null;

            public function __construct(private bool $resposta)
            {
            }

            public function existeReferenciaA(Model $configuracao): bool
            {
                $this->recebido = $configuracao;

                return $this->resposta;
            }
        };
    }

    private function registar(string $nome, ReferenciaFinanceira $referencia): void
    {
        $this->app->instance($nome, $referencia);
        $this->app->tag([$nome], ReferenciasFinanceiras::ETIQUETA);
    }

    public function test_sem_implementacoes_nao_ha_referencia(): void
    {
        $this->assertFalse(app(ReferenciasFinanceiras::class)->existeReferenciaA(new class extends Model {}));
    }

    public function test_todas_negam_nao_ha_referencia(): void
    {
        $this->registar('ref.a', $this->referencia(false));
        $this->registar('ref.b', $this->referencia(false));

        $this->assertFalse(app(ReferenciasFinanceiras::class)->existeReferenciaA(new class extends Model {}));
    }

    public function test_basta_uma_afirmar_para_haver_referencia(): void
    {
        $this->registar('ref.a', $this->referencia(false));
        $this->registar('ref.b', $this->referencia(true));

        $this->assertTrue(app(ReferenciasFinanceiras::class)->existeReferenciaA(new class extends Model {}));
    }

    public function test_a_implementacao_recebe_o_modelo_consultado(): void
    {
        $referencia = $this->referencia(false);
        $this->registar('ref.a', $referencia);
        $modelo = new class extends Model {};

        app(ReferenciasFinanceiras::class)->existeReferenciaA($modelo);

        $this->assertSame($modelo, $referencia->recebido);
    }
}
