<?php

namespace Modules\AnoLectivo\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;

trait GarantiaAnoLectivoAtivoUnico
{
    protected function garantirUnicoAtivo(?int $estabelecimentoId, ?int $idAtual = null): void
    {
        $existeOutroAtivo = AnoLectivo::where('estado', EstadoAnoLectivo::ATIVO->value)
            ->where('estabelecimento_id', $estabelecimentoId)
            ->when($idAtual, fn ($query) => $query->where('id', '!=', $idAtual))
            ->lockForUpdate()
            ->exists();

        if ($existeOutroAtivo) {
            throw ValidationException::withMessages([
                'estado' => 'Já existe um Ano Lectivo activo para este estabelecimento.',
            ]);
        }
    }
}
