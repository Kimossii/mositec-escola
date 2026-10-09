<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonInterface;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Support\CambioResolvido;
use Modules\Financeiro\Support\TaxaCambio;

/**
 * O câmbio (1 USD = X unidades da moeda da escola) à data dada: o ÚLTIMO câmbio até essa data.
 * Moeda da escola = USD -> 1. Modo manual -> só a tabela da escola. Caso contrário, a da
 * plataforma. Sem câmbio -> null: nunca bloqueia.
 */
class CambioDoDia
{
    public const REFERENCIA = 'USD';

    public function para(CarbonInterface $data): ?TaxaCambio
    {
        return $this->resolver($data)?->taxa;
    }

    public function resolver(CarbonInterface $data): ?CambioResolvido
    {
        $configuracao = ConfiguracaoMonetaria::doTenant();

        if ($configuracao->moeda === self::REFERENCIA) {
            return new CambioResolvido(TaxaCambio::um(), null, 'usd');
        }

        $consulta = $configuracao->cambio_manual ? Cambio::query() : CambioPlataforma::query();

        $linha = $consulta
            ->where('moeda_cotada', $configuracao->moeda)
            ->where('moeda_base', self::REFERENCIA)
            ->whereDate('data', '<=', $data->toDateString())
            ->orderByDesc('data')
            ->first();

        if ($linha === null) {
            return null;
        }

        return new CambioResolvido(
            $linha->taxaCambio(),
            $linha->data->toDateString(),
            $configuracao->cambio_manual ? 'escola' : 'plataforma',
        );
    }
}
