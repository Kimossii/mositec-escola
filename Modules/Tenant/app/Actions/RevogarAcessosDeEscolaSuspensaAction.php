<?php

namespace Modules\Tenant\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Exceptions\AcessosNaoRevogaveis;
use Modules\Tenant\Models\Tenant;

/**
 * Termina as sessões e os tokens de uma escola que JÁ está suspensa (a revogação feita no momento de
 * suspender continua a usar a RevogarAcessosAposSuspensaoAction directamente). Só a escola Suspensa
 * é aceite: numa Activa seria desligar utilizadores em uso, numa Encerrada não há acessos a gerir.
 * O estado é lido da BD com bloqueio: o objecto recebido pode estar desactualizado, e a escola não
 * pode ser reactivada a meio da revogação.
 */
class RevogarAcessosDeEscolaSuspensaAction
{
    public function __construct(private readonly RevogarAcessosAposSuspensaoAction $revogar) {}

    /**
     * @return array{sessoes: int, tokens: int} quantidades apagadas
     *
     * @throws AcessosNaoRevogaveis
     */
    public function executar(Tenant $tenant): array
    {
        return DB::transaction(function () use ($tenant) {
            $actual = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

            if ($actual->estado !== EstadoTenant::SUSPENSO) {
                throw AcessosNaoRevogaveis::para($actual->codigo, $actual->estado);
            }

            return $this->revogar->executar($actual);
        });
    }
}
