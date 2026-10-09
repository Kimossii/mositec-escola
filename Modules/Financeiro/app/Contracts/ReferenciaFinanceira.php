<?php

namespace Modules\Financeiro\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Implementada pelos módulos operacionais (propinas, pagamentos, vendas...) para dizerem
 * se algum registo seu usa uma configuração financeira (método de pagamento, produto,
 * serviço, plano). Impede eliminar configuração com histórico: desactiva-se em vez disso.
 * Registo no container: $this->app->tag([Classe::class], ReferenciasFinanceiras::ETIQUETA).
 */
interface ReferenciaFinanceira
{
    public function existeReferenciaA(Model $configuracao): bool;
}
