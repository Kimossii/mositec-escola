<?php

namespace Modules\Tenant\Services;

use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Models\Tenant;

/**
 * Códigos de tenant: `MOSI-` e seis dígitos. O seguinte é o maior existente + 1
 * (não deriva do id; a unicidade final é garantida pelo índice da BD).
 */
class GeradorCodigoTenant
{
    public const PADRAO = '/^MOSI-(?!000000)\d{6}$/';

    public function formatoValido(string $codigo): bool
    {
        return preg_match(self::PADRAO, $codigo) === 1;
    }

    public function existe(string $codigo): bool
    {
        return Tenant::query()->where('codigo', $codigo)->exists();
    }

    public function proximo(): string
    {
        $ultimo = Tenant::query()
            ->where('codigo', 'like', 'MOSI-%')
            ->orderByDesc('codigo')
            ->value('codigo');

        $numero = $ultimo !== null ? (int) substr($ultimo, 5) : 0;

        if ($numero >= 999999) {
            throw new DadosDeTenantInvalidos(['codigo' => 'Esgotaram-se os códigos MOSI-000001 a MOSI-999999; indique um com --codigo.']);
        }

        return sprintf('MOSI-%06d', $numero + 1);
    }
}
