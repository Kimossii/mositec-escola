<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\DTO\LigacaoDeClassesDTO;
use Modules\Avaliacao\DTO\RegraQualitativaDTO;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoRegraNivel;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Core\Traits\LogService;
use Modules\Turma\Models\NivelAcademico;

/** Cria ou actualiza uma regra qualitativa (Creche, Pré-Escolar) de um ano lectivo e liga-lhe as classes. */
class GuardarRegraQualitativaAction
{
    use LogService;

    public function __construct(private LigarClassesARegraAction $ligar)
    {
    }

    public function executar(RegraQualitativaDTO $dto): AvaliacaoRegraQualitativa
    {
        return $this->comLog('Guardar regra qualitativa', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            AnoLectivo::query()->findOrFail($dto->ano_lectivo_id);

            if ($dto->escala_id !== null) {
                AvaliacaoEscala::query()->findOrFail($dto->escala_id);
            }

            return DB::transaction(function () use ($dto) {
                $regra = $this->regraExistente($dto);
                $primeira = NivelAcademico::query()->whereIn('id', $dto->nivel_ids)->orderBy('ordem')->first();
                $campos = [
                    'ano_lectivo_id' => $dto->ano_lectivo_id,
                    'nome' => trim($dto->nome) !== '' ? trim($dto->nome) : ($regra?->nome ?: (string) ($primeira?->nome ?? 'Nova regra qualitativa')),
                    'escala_id' => $dto->escala_id,
                    'progressao' => $dto->progressao,
                ];

                $regra = $regra === null ? AvaliacaoRegraQualitativa::create($campos) : tap($regra)->update($campos);
                $this->ligar->executar(new LigacaoDeClassesDTO($dto->ano_lectivo_id, $dto->nivel_ids, true, $regra->id));

                return $regra;
            });
        });
    }

    /** A regra indicada ou, sem indicação, a que a primeira classe já segue neste ano. */
    private function regraExistente(RegraQualitativaDTO $dto): ?AvaliacaoRegraQualitativa
    {
        if ($dto->regra_id !== null) {
            $regra = AvaliacaoRegraQualitativa::query()->findOrFail($dto->regra_id);

            if ($regra->ano_lectivo_id !== $dto->ano_lectivo_id) {
                throw ValidationException::withMessages(['regra_id' => 'A regra não pertence a este ano lectivo.']);
            }

            return $regra;
        }

        if ($dto->nova) {
            return null;
        }

        return AvaliacaoRegraNivel::query()->where('ano_lectivo_id', $dto->ano_lectivo_id)->whereIn('nivel_academico_id', $dto->nivel_ids)->whereNotNull('regra_qualitativa_id')->first()?->regraQualitativa;
    }
}
