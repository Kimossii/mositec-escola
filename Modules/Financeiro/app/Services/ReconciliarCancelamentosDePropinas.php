<?php

namespace Modules\Financeiro\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Throwable;

/**
 * Rede de segurança diária (D12; Q5; trancamento D14): cancela as propinas futuras canceláveis das matrículas
 * terminadas cujo cancelamento por estado não aconteceu. Mesmas regras e mesma escrita do listener (serviço
 * partilhado). Uma transacção por matrícula, com a matrícula bloqueada primeiro (D5). Idempotente.
 */
class ReconciliarCancelamentosDePropinas
{
    public function __construct(
        private CancelarPropinasFuturasDaMatricula $cancelar,
        private TenantContext $tenant,
    ) {
    }

    /**
     * `matriculas` = as que realmente tiveram propinas canceladas (não as meras candidatas, que podem ter só dívida
     * intocável e voltam a aparecer todos os dias sem trabalho a fazer).
     *
     * @return array{matriculas: int, canceladas: int, erros: int}
     */
    public function executar(): array
    {
        $terminais = array_map(fn (EstadoMatriculaEnum $estado) => $estado->value, CancelarPropinasFuturasDaMatricula::ESTADOS_TERMINAIS);

        // Só para escolher candidatas (o serviço volta a filtrar, já com a matrícula bloqueada). Em SQLite a
        // data_fim fica "Y-m-d 00:00:00" e periodo_inicio "Y-m-d": a comparação de texto continua correcta para ">".
        $ids = Propina::query()
            ->where('estado', EstadoCobranca::EM_ABERTO->value)
            ->whereHas('matricula', fn ($matricula) => $matricula
                ->whereIn('estado', $terminais)
                ->whereNotNull('data_fim')
                ->whereColumn('propinas.periodo_inicio', '>', 'matriculas.data_fim'))
            ->distinct()
            ->orderBy('matricula_id')
            ->pluck('matricula_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $canceladas = 0;
        $afectadas = 0;
        $erros = 0;

        foreach ($ids as $id) {
            try {
                $feitas = count(DB::transaction(function () use ($id) {
                    $matricula = Matricula::query()->whereKey($id)->lockForUpdate()->first();

                    if ($matricula === null || $matricula->data_fim === null) {
                        return [];
                    }

                    return $this->cancelar->executar($matricula, $matricula->data_fim->toDateString(), null, reconciliacao: true);
                }));
                $canceladas += $feitas;
                $afectadas += $feitas > 0 ? 1 : 0;
            } catch (Throwable $e) {
                // Já FORA da transacção desta matrícula, revertida por inteiro; as outras seguem.
                report($e);
                Log::warning('financeiro.reconciliar-cancelamentos.matricula-falhou', [
                    'tenant_id' => $this->tenant->id(),
                    'matricula_id' => $id,
                    'excepcao' => $e::class,
                    'mensagem' => $e->getMessage(),
                ]);
                $erros++;
            }
        }

        return ['matriculas' => $afectadas, 'canceladas' => $canceladas, 'erros' => $erros];
    }
}
