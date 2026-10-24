<?php

namespace Modules\Financeiro\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Financeiro\Contracts\FonteDePrecos;

class FontesDePrecos
{
    public const ETIQUETA = 'financeiro.fontes-de-precos';

    public function __construct(private Container $container)
    {
    }

    public function existem(): bool
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $fonte) {
            /** @var FonteDePrecos $fonte */
            if ($fonte->existemPrecos()) {
                return true;
            }
        }

        return false;
    }
}
