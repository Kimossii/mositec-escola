<?php

namespace Modules\Financeiro\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Services\GerarCobrancasObrigatorias;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Throwable;

/**
 * Ao passar a Activa, cobra os produtos e serviços obrigatórios, DEPOIS do commit. Falha nunca propaga: a matrícula
 * já está Activa e os obrigatórios cobram-se à mão (Nova cobrança).
 */
class GerarCobrancasObrigatoriasAoActivarMatricula implements ShouldHandleEventsAfterCommit
{
    public function __construct(private GerarCobrancasObrigatorias $obrigatorias, private TenantContext $tenant)
    {
    }

    public function handle(MatriculaEstadoAlterado $evento): void
    {
        if ($evento->estadoNovo !== EstadoMatriculaEnum::ACTIVA->value) {
            return;
        }

        try {
            $this->obrigatorias->executar($evento->matriculaId);
        } catch (Throwable $e) {
            Log::warning('financeiro.cobrancas-obrigatorias.falhou', [
                'tenant_id' => $this->tenant->temTenant() ? $this->tenant->id() : null,
                'matricula_id' => $evento->matriculaId,
                'excepcao' => $e::class,
                'mensagem' => $e->getMessage(),
            ]);
        }
    }
}
