<?php

namespace Modules\Core\Tenancy\Contracts;

/**
 * Endereço público de um tenant, para quem precisa de gerar ligações absolutas fora de um pedido HTTP
 * (emails em fila, comandos). O módulo Tenant implementa-o; o Core só conhece este contrato.
 */
interface EnderecoDoTenant
{
    /** Domínio principal normalizado (sem esquema), ou null se o tenant não tiver nenhum. */
    public function dominioPrincipal(int $tenantId): ?string;
}
