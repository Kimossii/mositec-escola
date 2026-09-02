<?php

namespace Modules\AnoLectivo\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarAnoLectivoAction
{
    use GarantiaAnoLectivoAtivoUnico;

    public function criar(AnoLectivoDTO $dto): AnoLectivo
    {
        return DB::transaction(function () use ($dto) {
            $estabelecimentoId = Estabelecimento::current()?->id;

            if ($dto->estado === EstadoAnoLectivo::ATIVO) {
                $this->garantirUnicoAtivo($estabelecimentoId);
            }

            return AnoLectivo::create([
                'estabelecimento_id' => $estabelecimentoId,
                'nome' => $dto->nome,
                'data_inicio' => $dto->dataInicio,
                'data_fim' => $dto->dataFim,
                'estado' => $dto->estado->value,
            ]);
        });
    }
}
