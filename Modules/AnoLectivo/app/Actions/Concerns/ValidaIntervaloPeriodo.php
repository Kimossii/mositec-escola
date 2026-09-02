<?php

namespace Modules\AnoLectivo\Actions\Concerns;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;

trait ValidaIntervaloPeriodo
{
    protected function garantirDentroDoIntervalo(AnoLectivo $anoLectivo, string $dataInicio, string $dataFim): void
    {
        if (Carbon::parse($dataInicio)->lt($anoLectivo->data_inicio) || Carbon::parse($dataFim)->gt($anoLectivo->data_fim)) {
            throw ValidationException::withMessages([
                'data_inicio' => 'As datas do período têm de estar dentro do intervalo do Ano Lectivo.',
            ]);
        }
    }

    protected function garantirSemSobreposicao(AnoLectivo $anoLectivo, string $dataInicio, string $dataFim, ?int $idAtual = null): void
    {
        $sobrepoe = $anoLectivo->periodos()
            ->when($idAtual, fn ($query) => $query->where('id', '!=', $idAtual))
            ->where('data_inicio', '<=', $dataFim)
            ->where('data_fim', '>=', $dataInicio)
            ->exists();

        if ($sobrepoe) {
            throw ValidationException::withMessages([
                'data_inicio' => 'Este período sobrepõe-se a outro período já existente no mesmo Ano Lectivo.',
            ]);
        }
    }
}
