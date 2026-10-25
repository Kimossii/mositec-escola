<?php

namespace Modules\Tenant\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Tenant;
use Modules\Core\Traits\LogService;

/**
 * Activo -> Suspenso (spec §6). Regista a data e o motivo (obrigatório). Não toca nos dados do tenant.
 * O estado é lido da BD com bloqueio dentro da transacção: o objecto recebido pode estar desactualizado.
 */
class SuspenderTenantAction
{
    use LogService;

    private const MOTIVO_MAX = 255;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Tenant $tenant, string $motivo): Tenant
    {
        return $this->comLog('Suspender escola', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($tenant, $motivo));
    }

    /**
     * @throws DadosDeTenantInvalidos
     * @throws TransicaoDeEstadoInvalida
     */
    private function executarSemRegisto(Tenant $tenant, string $motivo): Tenant
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new DadosDeTenantInvalidos(['motivo' => 'O motivo da suspensão é obrigatório.']);
        }

        if (mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new DadosDeTenantInvalidos(['motivo' => 'O motivo da suspensão não pode exceder ' . self::MOTIVO_MAX . ' caracteres.']);
        }

        return DB::transaction(function () use ($tenant, $motivo) {
            $actual = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

            if ($actual->estado !== EstadoTenant::ACTIVO) {
                throw TransicaoDeEstadoInvalida::para($actual->codigo, $actual->estado, 'suspender');
            }

            $actual->forceFill([
                'estado' => EstadoTenant::SUSPENSO,
                'suspenso_em' => now(),
                'motivo_suspensao' => $motivo,
            ])->save();

            $tenant->setRawAttributes($actual->getAttributes(), true);

            return $tenant;
        });
    }
}
