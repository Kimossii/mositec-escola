<?php

namespace Modules\Financeiro\Tests\Feature;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AnularPropinaAction;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

/**
 * Cancelar/anular propina com multa (spec Propinas §8, pontos F5).
 */
class CancelarAnularPropinaComMultaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    /** @return array{0: Propina, 1: PropinaMulta} */
    private function propinaComMulta(int $valorMulta = 25_000): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        return [$propina, $this->multa($propina, $valorMulta)];
    }

    private function assertRecusado(string $campo, Closure $operacao): void
    {
        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
        }
    }

    public function test_cancelar_propina_sem_pagamentos_de_multa_marca_a_multa_anulada(): void
    {
        [$propina, $multa] = $this->propinaComMulta();

        app(CancelarPropinaAction::class)->executar($propina->fresh(), new CancelarPropinaDTO('Motivo válido'), null);

        $this->assertSame(EstadoCobranca::CANCELADA, $propina->fresh()->estado);
        $this->assertSame(EstadoMulta::ANULADA, $multa->fresh()->estado);
    }

    public function test_cancelar_propina_com_pagamentos_de_multa_e_bloqueado(): void
    {
        [$propina, $multa] = $this->propinaComMulta();
        $pagamento = $this->pagamentoDirecto($propina->matricula);
        PagamentoMulta::create(['pagamento_id' => $pagamento->id, 'propina_multa_id' => $multa->id, 'valor' => 10_000]);

        $this->assertRecusado('propina', fn () => app(CancelarPropinaAction::class)->executar($propina->fresh(), new CancelarPropinaDTO('Motivo válido'), null));

        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->fresh()->estado);
        $this->assertSame(EstadoMulta::ACTIVA, $multa->fresh()->estado);
    }

    public function test_anular_propina_sem_pagamentos_de_multa_marca_a_multa_anulada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $pagamento = $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]]);
        $this->anularPagamento($pagamento); // histórico, sem pagamentos activos
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->fresh()->estado);

        $multa = $this->multa($propina, 25_000);

        app(AnularPropinaAction::class)->executar($propina->fresh(), new MotivoDeAnulacaoDTO('Motivo válido'), null);

        $this->assertSame(EstadoCobranca::ANULADA, $propina->fresh()->estado);
        $this->assertSame(EstadoMulta::ANULADA, $multa->fresh()->estado);
    }

    public function test_anular_propina_com_pagamentos_de_multa_e_bloqueado(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $pagamento = $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]]);
        $this->anularPagamento($pagamento); // histórico
        $multa = $this->multa($propina, 25_000);

        $pagamentoDaMulta = $this->pagamentoDirecto($matricula);
        PagamentoMulta::create(['pagamento_id' => $pagamentoDaMulta->id, 'propina_multa_id' => $multa->id, 'valor' => 10_000]);

        $this->assertRecusado('propina', fn () => app(AnularPropinaAction::class)->executar($propina->fresh(), new MotivoDeAnulacaoDTO('Motivo válido'), null));

        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->fresh()->estado);
        $this->assertSame(EstadoMulta::ACTIVA, $multa->fresh()->estado);
    }
}
