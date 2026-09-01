<?php

namespace Modules\AnoLectivo\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;

class AlterarEstadoAnoLectivoAction
{
    use GarantiaAnoLectivoAtivoUnico;

    public function alterar(AnoLectivo $anoLectivo, EstadoAnoLectivo $novoEstado): AnoLectivo
    {
        return DB::transaction(function () use ($anoLectivo, $novoEstado) {
            $estabelecimentoId = Estabelecimento::current()?->id ?? $anoLectivo->estabelecimento_id;

            if ($novoEstado === EstadoAnoLectivo::ATIVO) {
                $this->garantirUnicoAtivo($estabelecimentoId, $anoLectivo->id);
            }

            $anoLectivo->update([
                'estabelecimento_id' => $estabelecimentoId,
                'estado' => $novoEstado->value,
            ]);

            return $anoLectivo->fresh();
        });
    }
}
