<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\DTO\RegraQualitativaDTO;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Turma\Models\NivelAcademico;

/** Define a escala que uma classe qualitativa (Creche ou Pré-Escolar) usa num ano lectivo. */
class GuardarRegraQualitativaAction
{
    use LogService;

    public function executar(RegraQualitativaDTO $dto): AvaliacaoRegraQualitativa
    {
        return $this->comLog('Guardar regra qualitativa', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            AnoLectivo::query()->findOrFail($dto->ano_lectivo_id);
            AvaliacaoEscala::query()->findOrFail($dto->escala_id);
            $nivel = NivelAcademico::query()->findOrFail($dto->nivel_academico_id);

            if (! in_array($nivel->etapa_ensino, [EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::PRE_ESCOLAR], true)) {
                throw ValidationException::withMessages(['nivel_academico_id' => 'A avaliação qualitativa só se aplica à Creche e ao Pré-Escolar.']);
            }

            return DB::transaction(fn () => AvaliacaoRegraQualitativa::updateOrCreate(
                ['ano_lectivo_id' => $dto->ano_lectivo_id, 'nivel_academico_id' => $dto->nivel_academico_id],
                ['escala_id' => $dto->escala_id, 'progressao' => $dto->progressao],
            ));
        });
    }
}
