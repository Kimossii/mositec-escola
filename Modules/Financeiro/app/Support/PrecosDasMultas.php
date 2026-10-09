<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Contracts\FonteDePrecos;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\EscalaoMulta;

/**
 * Escalões de valor fixo contêm montantes na moeda da escola; os de percentagem não dependem dela.
 */
class PrecosDasMultas implements FonteDePrecos
{
    public function existemPrecos(): bool
    {
        return EscalaoMulta::query()->where('tipo', TipoMulta::VALOR_FIXO->value)->exists();
    }
}
