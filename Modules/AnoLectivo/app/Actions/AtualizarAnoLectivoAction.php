<?php

namespace Modules\AnoLectivo\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarAnoLectivoAction
{
    use GarantiaAnoLectivoAtivoUnico;

    public function atualizar(AnoLectivo $anoLectivo, AnoLectivoDTO $dto): AnoLectivo
    {
        return DB::transaction(function () use ($anoLectivo, $dto) {
            $estabelecimentoId = Estabelecimento::current()?->id ?? $anoLectivo->estabelecimento_id;

            if ($dto->estado === EstadoAnoLectivo::ATIVO) {
                $this->garantirUnicoAtivo($estabelecimentoId, $anoLectivo->id);
            }

            $this->garantirDependentesDentroDoNovoIntervalo($anoLectivo, $dto);

            $anoLectivo->update([
                'estabelecimento_id' => $estabelecimentoId,
                'nome' => $dto->nome,
                'data_inicio' => $dto->dataInicio,
                'data_fim' => $dto->dataFim,
                'estado' => $dto->estado->value,
            ]);

            return $anoLectivo->fresh();
        });
    }

    private function garantirDependentesDentroDoNovoIntervalo(AnoLectivo $anoLectivo, AnoLectivoDTO $dto): void
    {
        $novoDataInicio = Carbon::parse($dto->dataInicio)->toDateString();
        $novoDataFim = Carbon::parse($dto->dataFim)->toDateString();

        $temDependenteFora = $anoLectivo->periodos()
            ->where(fn ($query) => $query->where('data_inicio', '<', $novoDataInicio)
                ->orWhere('data_fim', '>', $novoDataFim))
            ->exists()
            || $anoLectivo->eventosCalendario()
                ->where(fn ($query) => $query->where('data_inicio', '<', $novoDataInicio)
                    ->orWhere('data_fim', '>', $novoDataFim))
                ->exists();

        if ($temDependenteFora) {
            throw ValidationException::withMessages([
                'data_inicio' => 'Existem períodos ou eventos que ficariam fora do novo intervalo do Ano Lectivo.',
            ]);
        }
    }
}
