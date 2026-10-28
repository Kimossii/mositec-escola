<?php

namespace Modules\Tenant\Actions;

use Modules\Core\Tenancy\Contracts\RevogaAcessosDoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Models\Tenant;
use Modules\Core\Traits\LogService;

/**
 * Termina as sessões e os tokens de API dos utilizadores de uma escola recém-suspensa. É a única
 * forma de o módulo Tenant abrir o contexto da escola para isto: o contexto abre-se aqui dentro,
 * é restaurado pelo `executarComo` (mesmo com excepção) e quem chama (comando, painel da
 * Plataforma) nunca o toca. Quem chama garante que a suspensão já teve sucesso: a Action não
 * decide o estado, só revoga.
 */
class RevogarAcessosAposSuspensaoAction
{
    use LogService;

    public function __construct(private readonly TenantContext $contexto) {}

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Tenant $tenant): array
    {
        return $this->comLog('Revogar acessos apos suspensao', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($tenant));
    }

    /** @return array{sessoes: int, tokens: int} quantidades apagadas */
    private function executarSemRegisto(Tenant $tenant): array
    {
        return $this->contexto->executarComo($tenant->paraTenantAtual(), fn () => app(RevogaAcessosDoTenant::class)->revogar());
    }
}
