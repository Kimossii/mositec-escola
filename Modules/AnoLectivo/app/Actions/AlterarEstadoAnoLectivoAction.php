<?php

namespace Modules\AnoLectivo\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

class AlterarEstadoAnoLectivoAction
{
    use GarantiaAnoLectivoAtivoUnico;

    public function __construct(
        private AlterarEstadoMatriculaAction $alterarEstadoMatricula,
    ) {
    }

    public function alterar(
        AnoLectivo $anoLectivo,
        EstadoAnoLectivo $novoEstado,
        bool $confirmarEncerramentoMatriculas = false,
        ?int $utilizadorId = null,
    ): AnoLectivo {
        return DB::transaction(function () use ($anoLectivo, $novoEstado, $confirmarEncerramentoMatriculas, $utilizadorId) {
            $estabelecimentoId = Estabelecimento::current()?->id ?? $anoLectivo->estabelecimento_id;

            if ($novoEstado === EstadoAnoLectivo::ATIVO) {
                $this->garantirUnicoAtivo($estabelecimentoId, $anoLectivo->id);
            }

            if ($novoEstado === EstadoAnoLectivo::ENCERRADO) {
                $this->encerrarMatriculas($anoLectivo, $confirmarEncerramentoMatriculas, $utilizadorId);
            }

            $anoLectivo->update([
                'estabelecimento_id' => $estabelecimentoId,
                'estado' => $novoEstado->value,
            ]);

            return $anoLectivo->fresh();
        });
    }

    /**
     * Ao encerrar, decidir se cada matrícula Activa terminou o ano com
     * sucesso é uma decisão académica humana — o sistema não deve assumir
     * isso sozinho. Por isso exige confirmação explícita antes de tocar em
     * qualquer matrícula; só depois de confirmado é que assume Activa ->
     * Concluída e Pendente -> Cancelada (esta sem julgamento académico
     * envolvido, nunca chegou a ser activada).
     */
    private function encerrarMatriculas(AnoLectivo $anoLectivo, bool $confirmado, ?int $utilizadorId): void
    {
        $activas = Matricula::where('ano_lectivo_id', $anoLectivo->id)
            ->where('estado', EstadoMatriculaEnum::ACTIVA->value)
            ->get();
        $pendentes = Matricula::where('ano_lectivo_id', $anoLectivo->id)
            ->where('estado', EstadoMatriculaEnum::PENDENTE->value)
            ->get();

        if ($activas->isEmpty() && $pendentes->isEmpty()) {
            return;
        }

        if (! $confirmado) {
            throw ValidationException::withMessages([
                'ano_lectivo' => "Este Ano Lectivo tem {$activas->count()} matrícula(s) Activa(s) e {$pendentes->count()} Pendente(s). Ao encerrar, as Activas passam a Concluída e as Pendentes a Cancelada. Confirma?",
            ]);
        }

        foreach ($activas as $matricula) {
            $this->alterarEstadoMatricula->executar($matricula, EstadoMatriculaEnum::CONCLUIDA, $utilizadorId);
        }

        foreach ($pendentes as $matricula) {
            $this->alterarEstadoMatricula->executar($matricula, EstadoMatriculaEnum::CANCELADA, $utilizadorId);
        }
    }
}
