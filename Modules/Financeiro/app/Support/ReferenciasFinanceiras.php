<?php

namespace Modules\Financeiro\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;

class ReferenciasFinanceiras
{
    public const ETIQUETA = 'financeiro.referencias';

    public function __construct(private Container $container)
    {
    }

    public function existeReferenciaA(Model $configuracao): bool
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $referencia) {
            /** @var ReferenciaFinanceira $referencia */
            if ($referencia->existeReferenciaA($configuracao)) {
                return true;
            }
        }

        return false;
    }
}
