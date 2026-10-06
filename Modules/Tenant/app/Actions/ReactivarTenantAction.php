<?php

namespace Modules\Tenant\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Tenant;

/**
 * Suspenso -> Activo (spec §6). Limpa a suspensão (data e motivo) e regista o instante da
 * reactivação em `reactivado_em` (a última, se houver várias).
 */
class ReactivarTenantAction
{
    /**
     * @throws TransicaoDeEstadoInvalida
     */
    public function executar(Tenant $tenant): Tenant
    {
        return DB::transaction(function () use ($tenant) {
            $actual = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

            if ($actual->estado !== EstadoTenant::SUSPENSO) {
                throw TransicaoDeEstadoInvalida::para($actual->codigo, $actual->estado, 'reactivar');
            }

            $actual->forceFill([
                'estado' => EstadoTenant::ACTIVO,
                'suspenso_em' => null,
                'motivo_suspensao' => null,
                'reactivado_em' => now(),
            ])->save();

            $tenant->setRawAttributes($actual->getAttributes(), true);

            return $tenant;
        });
    }
}
