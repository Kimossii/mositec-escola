<?php

namespace Modules\Tenant\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Tenant;
use Modules\Core\Traits\LogService;

/**
 * Activo | Suspenso -> Encerrado (terminal, spec §6). Regista a data e, se vier, o motivo (opcional:
 * aparado, vazio fica nulo, máximo 255). Dados e histórico da suspensão ficam intactos; reabrir e
 * purgar são manuais.
 */
class EncerrarTenantAction
{
    use LogService;

    private const MOTIVO_MAX = 255;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Tenant $tenant, ?string $motivo = null): Tenant
    {
        return $this->comLog('Encerrar escola', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($tenant, $motivo));
    }

    /**
     * @throws DadosDeTenantInvalidos
     * @throws TransicaoDeEstadoInvalida
     */
    private function executarSemRegisto(Tenant $tenant, ?string $motivo = null): Tenant
    {
        $motivo = trim((string) $motivo);
        $motivo = $motivo !== '' ? $motivo : null;

        if ($motivo !== null && mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new DadosDeTenantInvalidos(['motivo' => 'O motivo do encerramento não pode exceder ' . self::MOTIVO_MAX . ' caracteres.']);
        }

        return DB::transaction(function () use ($tenant, $motivo) {
            $actual = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

            if ($actual->estado === EstadoTenant::ENCERRADO) {
                throw TransicaoDeEstadoInvalida::para($actual->codigo, $actual->estado, 'encerrar');
            }

            $actual->forceFill([
                'estado' => EstadoTenant::ENCERRADO,
                'encerrado_em' => now(),
                'motivo_encerramento' => $motivo,
            ])->save();

            $tenant->setRawAttributes($actual->getAttributes(), true);

            return $tenant;
        });
    }
}
