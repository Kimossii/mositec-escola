<?php

namespace Modules\Tenant\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Tenant\Exceptions\DominioNaoRemovivel;
use Modules\Tenant\Models\Tenant;

/**
 * Remove um domínio do tenant. Recusa o principal e o último: o tenant tem de continuar
 * acessível. (O spec não prevê trocar de principal, por isso o principal nunca sai.)
 */
class RemoverDominioAction
{
    /**
     * @throws DominioNaoRemovivel
     */
    public function executar(Tenant $tenant, string $dominio): void
    {
        $host = NormalizadorHost::normalizar($dominio);

        DB::transaction(function () use ($tenant, $host) {
            $actual = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

            if ($actual->estado === EstadoTenant::ENCERRADO) {
                throw new DominioNaoRemovivel("O tenant {$actual->codigo} está Encerrado: os seus domínios ficam reservados e não podem ser removidos (evita que outro tenant herde links antigos).");
            }

            $registo = $actual->dominios()->where('dominio', $host)->first();

            if ($registo === null) {
                throw new DominioNaoRemovivel("O domínio '{$host}' não pertence ao tenant {$actual->codigo}.");
            }

            if ($registo->is_principal) {
                throw new DominioNaoRemovivel("O domínio '{$host}' é o principal do tenant {$actual->codigo} e não pode ser removido.");
            }

            if ($actual->dominios()->count() <= 1) {
                throw new DominioNaoRemovivel("O domínio '{$host}' é o único do tenant {$actual->codigo} e não pode ser removido.");
            }

            $registo->delete();
        });
    }
}
