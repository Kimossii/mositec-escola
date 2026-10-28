<?php

namespace Modules\Core\Tenancy\Support;

use Illuminate\Session\DatabaseSessionHandler;
use Modules\Core\Tenancy\TenantContext;

/**
 * Handler de sessões da BD que só pergunta «quem é o utilizador» ao guard por omissão quando há tenant resolvido.
 *
 * O handler de origem resolve o utilizador do guard `web` para preencher `sessions.user_id`, e esse utilizador vem de
 * um modelo com escopo de tenant. Num pedido sem tenant (o painel da Plataforma) isso lançava `TenantNaoResolvido`
 * (500) quando o cookie do painel trazia o id de uma sessão de escola autenticada. Sem tenant a linha fica com
 * `user_id` nulo, que é o que as sessões da Plataforma já devem ter (decisão D5 do Plano 13).
 */
class SessaoDatabaseSemTenantSegura extends DatabaseSessionHandler
{
    protected function userId()
    {
        return $this->container->make(TenantContext::class)->temTenant() ? parent::userId() : null;
    }
}
