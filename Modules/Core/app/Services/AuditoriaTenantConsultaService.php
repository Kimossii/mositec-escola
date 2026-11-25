<?php

namespace Modules\Core\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Core\Models\AuditoriaTenant;

/** Leitura do rasto da escola (`auditoria_tenant`), isolado por tenant pelo próprio Model. */
class AuditoriaTenantConsultaService
{
    /** @param array{accao?: ?string, utilizador?: ?string, entidade_tipo?: ?string, de?: ?string, ate?: ?string} $filtros */
    public function listar(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        return AuditoriaTenant::query()
            ->with('utilizador:id,name')
            ->when($filtros['accao'] ?? null, fn ($q, $v) => $q->where('accao', 'like', addcslashes($v, '%_\\').'%'))
            ->when($filtros['entidade_tipo'] ?? null, fn ($q, $v) => $q->where('entidade_tipo', $v))
            ->when($filtros['utilizador'] ?? null, fn ($q, $v) => $q->whereHas('utilizador', fn ($u) => $u->where('name', 'like', '%'.addcslashes($v, '%_\\').'%')))
            ->when($filtros['de'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['ate'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('created_at')
            ->latest('id')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (AuditoriaTenant $a) => [
                'id' => $a->id,
                'quando' => $a->created_at?->format('Y-m-d H:i:s'),
                'utilizador' => $a->utilizador?->name,
                'accao' => $a->accao,
                'entidade_tipo' => $a->entidade_tipo,
                'entidade_id' => $a->entidade_id,
                'detalhe' => $a->detalhe,
                'ip' => $a->ip,
            ]);
    }
}
