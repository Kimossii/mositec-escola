<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\Moeda;
use Modules\Financeiro\Support\ReferenciasFinanceiras;

/**
 * A moeda da escola corrente. Ponto único para saber em que moeda se interpretam, validam e
 * formatam os valores.
 */
class MoedaDoTenant
{
    public function __construct(
        private FontesDePrecos $fontesDePrecos,
        private ReferenciasFinanceiras $referencias,
    ) {
    }

    public function configuracao(): ConfiguracaoMonetaria
    {
        return ConfiguracaoMonetaria::doTenant();
    }

    public function atual(): Moeda
    {
        return Moeda::de($this->configuracao()->moeda);
    }

    /**
     * A moeda só pode mudar se não existirem preços de configuração nem registos financeiros.
     */
    public function podeAlterar(): bool
    {
        return ! $this->fontesDePrecos->existem()
            && ! $this->referencias->existeReferenciaA($this->configuracao());
    }
}
