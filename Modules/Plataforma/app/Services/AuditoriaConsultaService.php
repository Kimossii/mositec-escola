<?php

namespace Modules\Plataforma\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Plataforma\Models\RegistoDeAuditoria;

/**
 * Leitura do rasto da Plataforma (`plataforma_auditoria`, tabela global).
 */
class AuditoriaConsultaService
{
    /**
     * As acções mais recentes sobre uma escola, da mais nova para a mais antiga, com o autor já carregado.
     *
     * @return Collection<int, RegistoDeAuditoria>
     */
    public function recentesDaEscola(string $codigoTenant, int $limite = 20): Collection
    {
        return RegistoDeAuditoria::query()
            ->with('autor:id,name')
            ->where('codigo_tenant', $codigoTenant)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limite)
            ->get();
    }
}
