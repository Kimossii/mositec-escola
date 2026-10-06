<?php

namespace Modules\Tenant\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Tenant\Exceptions\DominioPrincipalInvalido;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;

/**
 * Passa o domínio principal da escola para outro domínio JÁ registado nela. O principal é a coluna
 * `domains.is_principal`, com um índice único parcial (no máximo um por tenant): por isso o anterior
 * é desmarcado ANTES de marcar o novo, e tudo corre numa só transacção (nunca fica a escola sem
 * principal nem com dois). Os dois domínios continuam a resolver a escola.
 */
class DefinirDominioPrincipalAction
{
    /**
     * @throws DominioPrincipalInvalido
     * @throws TransicaoDeEstadoInvalida
     */
    public function executar(Tenant $tenant, string $dominio): Domain
    {
        $host = NormalizadorHost::normalizar($dominio);

        return DB::transaction(function () use ($tenant, $host) {
            $actual = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

            if ($actual->estado === EstadoTenant::ENCERRADO) {
                throw TransicaoDeEstadoInvalida::para($actual->codigo, $actual->estado, 'alterar o domínio principal');
            }

            $dominios = $actual->dominios()->lockForUpdate()->get();
            $novo = $dominios->firstWhere('dominio', $host);

            if ($novo === null) {
                throw new DominioPrincipalInvalido("O domínio '{$host}' não pertence ao tenant {$actual->codigo}.");
            }

            if ($novo->is_principal) {
                throw new DominioPrincipalInvalido("O domínio '{$host}' já é o principal do tenant {$actual->codigo}.");
            }

            foreach ($dominios->where('is_principal', true) as $anterior) {
                $anterior->forceFill(['is_principal' => false])->save();
            }

            $novo->forceFill(['is_principal' => true])->save();

            return $novo;
        });
    }
}
