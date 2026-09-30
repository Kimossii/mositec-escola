<?php

namespace Modules\Core\Tenancy\Validation;

use Illuminate\Validation\DatabasePresenceVerifier;
use Modules\Core\Tenancy\TenantContext;

/**
 * As regras exists e unique consultam a tabela pelo query builder, sem passar
 * pelo Eloquent, por isso ignoram o TenantScope. Este verificador aplica a
 * mesma regra nesse caminho: ler uma tabela de tenant filtra sempre pelo tenant.
 *
 * Uma tabela que não esteja em nenhuma lista de config/tenancy.php é tratada
 * como tabela de tenant: o comportamento por omissão é o seguro.
 */
class VerificadorPresencaTenant extends DatabasePresenceVerifier
{
    protected function table($table)
    {
        $query = parent::table($table);

        if ($this->semFiltro($table)) {
            return $query;
        }

        // TenantContext::id() lança TenantNaoResolvido se não houver tenant.
        return $query->where($table . '.tenant_id', app(TenantContext::class)->id());
    }

    private function semFiltro(string $tabela): bool
    {
        $nome = str_contains($tabela, '.') ? substr($tabela, strrpos($tabela, '.') + 1) : $tabela;

        return in_array($nome, [
            ...config('tenancy.tabelas_globais', []),
            ...config('tenancy.tabelas_infraestrutura', []),
            ...config('tenancy.tabelas_por_converter', []),
        ], true);
    }
}
