<?php

namespace Modules\Tenant\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Tenant;

/**
 * Activo | Suspenso -> Encerrado (terminal, spec §6). Regista a data; o spec não prevê motivo
 * de encerramento. Dados e histórico da suspensão ficam intactos; reabrir e purgar são manuais.
 */
class EncerrarTenantAction
{
    /**
     * @throws TransicaoDeEstadoInvalida
     */
    public function executar(Tenant $tenant): Tenant
    {
        return DB::transaction(function () use ($tenant) {
            $actual = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

            if ($actual->estado === EstadoTenant::ENCERRADO) {
                throw TransicaoDeEstadoInvalida::para($actual->codigo, $actual->estado, 'encerrar');
            }

            $actual->forceFill([
                'estado' => EstadoTenant::ENCERRADO,
                'encerrado_em' => now(),
            ])->save();

            $tenant->setRawAttributes($actual->getAttributes(), true);

            return $tenant;
        });
    }
}
