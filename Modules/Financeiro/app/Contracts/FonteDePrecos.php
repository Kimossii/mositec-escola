<?php

namespace Modules\Financeiro\Contracts;

/**
 * Implementada por quem guarda PREÇOS de configuração (catálogo, planos de propina…). Enquanto
 * alguma devolver true, a moeda da escola não pode mudar: os valores seriam reinterpretados sem
 * conversão. Registo no container: $this->app->tag([Classe::class], FontesDePrecos::ETIQUETA).
 */
interface FonteDePrecos
{
    public function existemPrecos(): bool;
}
