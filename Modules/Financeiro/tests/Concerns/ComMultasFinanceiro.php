<?php

namespace Modules\Financeiro\Tests\Concerns;

use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\RegraCobranca;

/**
 * Multas por atraso para testes (Fase 1). Requer ComPropinasFinanceiro na mesma classe. As multas criadas aqui
 * NÃO passam pelo comando de aplicação (Grupo 3): escrevem o snapshot directamente, para exercitar models/serviços.
 */
trait ComMultasFinanceiro
{
    /**
     * @param  array<string, mixed>  $atributos  sobrepõe qualquer atributo preenchível (escalao_ordem, tipo, valor, …)
     */
    protected function multa(Propina $propina, int $valor = 25_000, array $atributos = []): PropinaMulta
    {
        return PropinaMulta::create(array_merge([
            'propina_id' => $propina->id,
            'escalao_ordem' => 1,
            'tipo' => TipoMulta::PERCENTAGEM,
            'valor_regra' => 500,
            'base_capital' => $propina->valor,
            'valor' => $valor,
        ], $atributos));
    }

    /**
     * Liga as multas e substitui os escalões (para o comando de aplicação, Grupo 3).
     *
     * @param  list<array{0: int, 1: TipoMulta, 2: int}>  $escaloes  [dias_atraso, tipo, valor]
     */
    protected function multaActiva(array $escaloes): RegraCobranca
    {
        $regra = RegraCobranca::doTenant();
        $regra->update(['multa_activa' => true]);
        $regra->escaloes()->delete();

        foreach (array_values($escaloes) as $i => [$dias, $tipo, $valor]) {
            $regra->escaloes()->create(['ordem' => $i + 1, 'dias_atraso' => $dias, 'tipo' => $tipo, 'valor' => $valor]);
        }

        return $regra->fresh();
    }
}
