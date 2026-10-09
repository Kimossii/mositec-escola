<?php

namespace Modules\AnoLectivo\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Support\DependenciasRegistadasDoAnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarAnoLectivoAction
{
    use GarantiaAnoLectivoAtivoUnico;

    public function atualizar(AnoLectivo $anoLectivo, AnoLectivoDTO $dto): AnoLectivo
    {
        return DB::transaction(function () use ($anoLectivo, $dto) {
            $estabelecimentoId = Estabelecimento::current()->id;

            if ($dto->estado === EstadoAnoLectivo::ATIVO) {
                $this->garantirUnicoAtivo($estabelecimentoId, $anoLectivo->id);
            }

            $this->garantirInicioCompativelComDependencias($anoLectivo, $dto);
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

    /**
     * Mudar o mês (ou ano) de data_inicio desloca competências de módulos dependentes (ex.:
     * planos de propina); mudar só o dia dentro do mesmo mês é livre.
     */
    private function garantirInicioCompativelComDependencias(AnoLectivo $anoLectivo, AnoLectivoDTO $dto): void
    {
        $actual = Carbon::parse($anoLectivo->data_inicio);
        $novo = Carbon::parse($dto->dataInicio);

        if ($actual->format('Y-m') === $novo->format('Y-m')) {
            return;
        }

        if (($motivo = app(DependenciasRegistadasDoAnoLectivo::class)->bloqueiaAlteracaoDeInicio($anoLectivo)) !== null) {
            throw ValidationException::withMessages(['data_inicio' => $motivo]);
        }
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
