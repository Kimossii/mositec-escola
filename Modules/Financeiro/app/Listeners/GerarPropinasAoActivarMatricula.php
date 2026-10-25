<?php

namespace Modules\Financeiro\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Throwable;
use Modules\Core\Traits\LogService;

/**
 * Q7 (+ §8.2/3 "geração recuperável"): ao passar a Activa, gera as propinas da matrícula DEPOIS do commit
 * (ShouldHandleEventsAfterCommit: o Dispatcher adia-o até a transacção mais exterior confirmar). Só com «gerar
 * automaticamente» nas Regras de Cobrança. Qualquer falha
 * é registada e NUNCA propaga: a matrícula já está Activa e o comando diário (idempotente) gera o que faltar.
 */
class GerarPropinasAoActivarMatricula implements ShouldHandleEventsAfterCommit
{
    use LogService;

    public function __construct(
        private GerarPropinasService $geracao,
        private TenantContext $tenant,
    ) {
    }

    public function handle(MatriculaEstadoAlterado $evento): void
    {
        if ($evento->estadoNovo !== EstadoMatriculaEnum::ACTIVA->value) {
            return;
        }

        try {
            $this->gerar($evento);
        } catch (Throwable $e) {
            // Já depois do commit e fora de qualquer transacção de negócio: apanhar aqui é seguro.
            if (! $e instanceof ValidationException) {
                report($e);
            }

            $this->logWarning('financeiro.gerar-propinas.activacao-falhou', [
                'tenant_id' => $this->tenant->temTenant() ? $this->tenant->id() : null,
                'matricula_id' => $evento->matriculaId,
                'excepcao' => $e::class,
                'mensagem' => $e->getMessage(),
            ]);
        }
    }

    private function gerar(MatriculaEstadoAlterado $evento): void
    {
        // Opção da escola (Q7). Leitura pura: sem firstOrCreate.
        if (! (RegraCobranca::query()->first()?->gerar_automaticamente ?? false)) {
            return;
        }

        $matricula = Matricula::query()->find($evento->matriculaId);

        // Pode ter mudado outra vez entre o commit e agora: decide o estado actual.
        if ($matricula === null || $matricula->estado !== EstadoMatriculaEnum::ACTIVA) {
            return;
        }

        $totais = $this->geracao->gerar(PedidoGeracaoPropinasDTO::daMatricula((int) $matricula->ano_lectivo_id, (string) $matricula->numero_registo_matricula))->totais();

        $this->logInfo('financeiro.gerar-propinas.activacao', [
            'tenant_id' => $this->tenant->temTenant() ? $this->tenant->id() : null,
            'matricula_id' => $matricula->id,
        ] + $totais);
    }
}
