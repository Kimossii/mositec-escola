<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Contracts\FonteDePrecos;
use Modules\Financeiro\Models\PlanoPropina;

class PrecosDosPlanos implements FonteDePrecos
{
    public function existemPrecos(): bool
    {
        return PlanoPropina::query()->exists();
    }
}
