<?php

namespace Modules\Financeiro\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Implementada pelos módulos operacionais (propinas, pagamentos, vendas...) para dizerem
 * se algum registo seu usa uma configuração financeira (método de pagamento, produto,
 * serviço, plano). Impede eliminar configuração com histórico: desactiva-se em vez disso.
 * Para a ConfiguracaoMonetaria (bloqueio da moeda da escola) deve devolver true se existir
 * QUALQUER registo operacional da escola, não só os que apontam a um model concreto: devolver false
 * por o model não ser conhecido deixaria mudar a moeda com valores já gravados.
 * Registo no container: $this->app->tag([Classe::class], ReferenciasFinanceiras::ETIQUETA).
 */
interface ReferenciaFinanceira
{
    public function existeReferenciaA(Model $configuracao): bool;
}
