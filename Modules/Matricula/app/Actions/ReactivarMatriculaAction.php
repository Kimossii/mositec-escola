<?php

namespace Modules\Matricula\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Core\Traits\LogService;

/** Reactivar (TRANCADA → ACTIVA): ano lectivo activo, data entre o trancamento e hoje, motivo obrigatório. */
class ReactivarMatriculaAction
{
    use LogService;

    public function __construct(
        private RegistarHistoricoMatriculaAction $registarHistorico,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Matricula $matricula, string $dataEfeito, string $motivo, ?int $utilizadorId = null): Matricula
    {
        return $this->comLog('Reactivar matrícula', $this->contextoDeArgumentos(func_get_args()), fn () => $this->executarSemRegisto($matricula, $dataEfeito, $motivo, $utilizadorId));
    }

    private function executarSemRegisto(Matricula $matricula, string $dataEfeito, string $motivo, ?int $utilizadorId = null): Matricula
    {
        return DB::transaction(function () use ($matricula, $dataEfeito, $motivo, $utilizadorId) {
            $bloqueada = Matricula::query()->whereKey($matricula->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoMatriculaEnum::TRANCADA) {
                throw ValidationException::withMessages(['estado' => 'Só se pode reactivar uma matrícula trancada.']);
            }

            if ($bloqueada->anoLectivo?->estado !== EstadoAnoLectivo::ATIVO) {
                throw ValidationException::withMessages(['estado' => 'O ano lectivo desta matrícula já não está activo: o aluno tem de fazer uma nova matrícula.']);
            }

            $data = CarbonImmutable::parse($dataEfeito)->toDateString();
            $trancadaEm = substr((string) MatriculaHistorico::query()
                ->where('matricula_id', $bloqueada->id)->where('estado_novo', EstadoMatriculaEnum::TRANCADA->value)->max('data_efeito'), 0, 10);

            if ($data > $this->relogio->hoje()->toDateString()) {
                throw ValidationException::withMessages(['data_efeito' => 'A data da reactivação não pode ser futura.']);
            }

            if ($trancadaEm !== '' && $data < $trancadaEm) {
                throw ValidationException::withMessages(['data_efeito' => 'A data da reactivação não pode ser anterior ao trancamento (' . CarbonImmutable::parse($trancadaEm)->format('d/m/Y') . ').']);
            }

            if (mb_strlen(trim($motivo)) < 5) {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo da reactivação (mínimo 5 caracteres).']);
            }

            $bloqueada->estado = EstadoMatriculaEnum::ACTIVA;
            $bloqueada->editado_por = $utilizadorId;
            $bloqueada->save();

            $this->registarHistorico->executar($bloqueada, EstadoMatriculaEnum::TRANCADA, EstadoMatriculaEnum::ACTIVA, $utilizadorId, $data, trim($motivo));

            MatriculaEstadoAlterado::dispatch((int) $bloqueada->id, EstadoMatriculaEnum::TRANCADA->value, EstadoMatriculaEnum::ACTIVA->value, $data, $utilizadorId);

            return $bloqueada->fresh();
        });
    }
}
