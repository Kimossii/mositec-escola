<?php

namespace Modules\Tenant\Actions;

use Modules\Core\Tenancy\Contracts\RevogaAcessosDoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Models\Tenant;

/**
 * Termina as sessões e os tokens de API dos utilizadores de uma escola recém-suspensa. É a única
 * forma de o módulo Tenant abrir o contexto da escola para isto: o contexto abre-se aqui dentro,
 * é restaurado pelo `executarComo` (mesmo com excepção) e quem chama (comando, painel da
 * Plataforma) nunca o toca. Quem chama garante que a suspensão já teve sucesso: a Action não
 * decide o estado, só revoga.
 */
class RevogarAcessosAposSuspensaoAction
{
    public function __construct(private readonly TenantContext $contexto) {}

    /** @return array{sessoes: int, tokens: int} quantidades apagadas */
    public function executar(Tenant $tenant): array
    {
        return $this->contexto->executarComo($tenant->paraTenantAtual(), fn () => app(RevogaAcessosDoTenant::class)->revogar());
    }
}
