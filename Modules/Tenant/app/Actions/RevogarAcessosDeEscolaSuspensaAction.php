<?php

namespace Modules\Tenant\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Exceptions\AcessosNaoRevogaveis;
use Modules\Tenant\Models\Tenant;
use Modules\Core\Traits\LogService;

/**
 * Termina as sessões e os tokens de uma escola que JÁ está suspensa (a revogação feita no momento de
 * suspender continua a usar a RevogarAcessosAposSuspensaoAction directamente). Só a escola Suspensa
 * é aceite: numa Activa seria desligar utilizadores em uso, numa Encerrada não há acessos a gerir.
 * O estado é lido da BD com bloqueio: o objecto recebido pode estar desactualizado, e a escola não
 * pode ser reactivada a meio da revogação.
 */
class RevogarAcessosDeEscolaSuspensaAction
{
    use LogService;

    public function __construct(private readonly RevogarAcessosAposSuspensaoAction $revogar) {}

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Tenant $tenant): array
    {
        return $this->comLog('Revogar acessos de escola suspensa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($tenant));
    }

    /**
     * @return array{sessoes: int, tokens: int} quantidades apagadas
     *
     * @throws AcessosNaoRevogaveis
     */
    private function executarSemRegisto(Tenant $tenant): array
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
