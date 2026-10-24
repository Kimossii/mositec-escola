<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Paridade com os triggers de imutabilidade (PostgreSQL): a data de criação e o autor da criação nunca mudam.
 * Só se tolera `criado_por` passar a nulo: a FK para o utilizador é ON DELETE SET NULL, logo apagar o utilizador
 * anula o autor sem que isso seja uma alteração indevida (o trigger pgsql também o permite). Qualquer outro valor
 * (trocar de autor) continua proibido.
 */
class AutoriaImutavel
{
    public static function verificar(Model $registo): void
    {
        $alterados = [];

        if ($registo->isDirty('created_at')) {
            $alterados[] = 'created_at';
        }

        if ($registo->isDirty('criado_por') && $registo->getAttribute('criado_por') !== null) {
            $alterados[] = 'criado_por';
        }

        if ($alterados !== []) {
            throw new LogicException('A data e o autor da criação nunca mudam (' . implode(', ', $alterados) . ').');
        }
    }
}
