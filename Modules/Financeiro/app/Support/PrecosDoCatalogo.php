<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Contracts\FonteDePrecos;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;

class PrecosDoCatalogo implements FonteDePrecos
{
    public function existemPrecos(): bool
    {
        return Produto::query()->exists() || Servico::query()->exists();
    }
}
