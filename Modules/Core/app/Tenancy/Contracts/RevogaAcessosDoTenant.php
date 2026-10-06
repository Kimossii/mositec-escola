<?php

namespace Modules\Core\Tenancy\Contracts;

/**
 * Termina o que mantém os utilizadores do tenant corrente com sessão iniciada (sessões e tokens
 * de API). Implementado pelo módulo Autenticacao; o módulo Tenant só conhece este contrato.
 * Corre dentro de TenantContext::executarComo().
 */
interface RevogaAcessosDoTenant
{
    /** @return array{sessoes: int, tokens: int} quantidades apagadas */
    public function revogar(): array;
}
