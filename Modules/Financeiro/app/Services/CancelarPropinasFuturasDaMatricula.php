<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\CancelamentoDePropinas;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

/**
 * Spec §9 + Q6: quando a matrícula termina (Cancelada, Transferida ou Concluída), cancela as propinas que começam
 * DEPOIS da data de efeito e que ainda se podem cancelar (Em Aberto, sem pagamentos nem histórico — D3). As outras
 * (períodos já começados, pagas, parciais, com histórico) ficam: são dívida e continuam pagáveis. Idempotente.
 * Usado pelo listener (na transacção da mudança de estado, Q5) e pela reconciliação diária.
 */
class CancelarPropinasFuturasDaMatricula
{
    /** @var list<EstadoMatriculaEnum> */
    public const ESTADOS_TERMINAIS = [EstadoMatriculaEnum::CANCELADA, EstadoMatriculaEnum::TRANSFERIDA, EstadoMatriculaEnum::CONCLUIDA];

    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private CancelamentoDePropinas $cancelamento,
    ) {
    }

    /**
     * Na transacção do chamador e com a matrícula JÁ bloqueada por ele (D5: matrícula → propinas por id crescente).
     *
     * @return list<int> ids das propinas canceladas agora
     */
    public function executar(Matricula $matricula, string $dataEfeito, ?int $utilizadorId, bool $reconciliacao = false): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Cancelar as propinas futuras de uma matrícula exige uma transacção aberta, com a matrícula bloqueada.');
        }

        if (! in_array($matricula->estado, self::ESTADOS_TERMINAIS, true)) {
            return [];
        }

        $ids = Propina::query()
            ->where('matricula_id', $matricula->id)
            ->where('estado', EstadoCobranca::EM_ABERTO->value)
            ->where('periodo_inicio', '>', $dataEfeito)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        $motivo = $this->motivo($matricula->estado, $dataEfeito, $reconciliacao);
        $canceladas = [];

        foreach ($this->bloqueio->bloquear($ids) as $propina) {
            // Relida sob bloqueio: um pagamento ou um cancelamento manual concorrente, já confirmado, é respeitado.
            if ($propina->periodo_inicio->toDateString() <= $dataEfeito || $this->cancelamento->motivoParaNaoCancelar($propina) !== null) {
                continue;
            }

            $this->cancelamento->cancelar($propina, $motivo, $utilizadorId);
            $canceladas[] = (int) $propina->id;
        }

        return $canceladas;
    }

    private function motivo(EstadoMatriculaEnum $estado, string $dataEfeito, bool $reconciliacao): string
    {
        $accao = match ($estado) {
            EstadoMatriculaEnum::CANCELADA => 'cancelada',
            EstadoMatriculaEnum::TRANSFERIDA => 'transferida',
            EstadoMatriculaEnum::CONCLUIDA => 'concluída',
            default => throw new LogicException("A matrícula não está num estado terminal ({$estado->label()})."),
        };
        $data = CarbonImmutable::createFromFormat('!Y-m-d', $dataEfeito)->format('d/m/Y');

        return "Matrícula {$accao} em {$data} (estado da matrícula" . ($reconciliacao ? ', reconciliação diária' : '') . ').';
    }
}
