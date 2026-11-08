<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\LigacaoDeClassesDTO;
use Modules\Avaliacao\Models\AvaliacaoRegraNivel;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Turma\Models\NivelAcademico;

/**
 * Liga um conjunto de classes a uma regra de um ano lectivo. Uma classe segue uma só regra por ano: as que se ligam aqui
 * saem da regra onde estavam, e as que a regra tinha e já não estão no conjunto ficam sem regra. A Creche e o Pré-Escolar
 * só se ligam a regras qualitativas; as restantes só a regras numéricas.
 */
class LigarClassesARegraAction
{
    use LogService;

    public function executar(LigacaoDeClassesDTO $dto): void
    {
        $this->comLog('Ligar classes a uma regra de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(LigacaoDeClassesDTO $dto): void
    {
        [$anoLectivoId, $qualitativa, $regraId] = [$dto->ano_lectivo_id, $dto->qualitativa, $dto->regra_id];
        $nivelIds = array_values(array_unique($dto->nivel_ids));
        $coluna = $qualitativa ? 'regra_qualitativa_id' : 'regra_id';

        foreach (NivelAcademico::query()->whereIn('id', $nivelIds)->get() as $nivel) {
            $ehQualitativo = in_array($nivel->etapa_ensino, [EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::PRE_ESCOLAR], true);

            if ($ehQualitativo !== $qualitativa) {
                throw ValidationException::withMessages(['nivel_ids' => $ehQualitativo
                    ? "«{$nivel->nome}» é avaliada de forma qualitativa: só pode seguir uma regra qualitativa."
                    : "«{$nivel->nome}» tem notas: só pode seguir uma regra numérica."]);
            }
        }

        if (NivelAcademico::query()->whereIn('id', $nivelIds)->count() !== count($nivelIds)) {
            throw ValidationException::withMessages(['nivel_ids' => 'Há uma classe indicada que não existe.']);
        }

        // Tira da regra as classes que já não são dela e, do ano, as que passam para esta.
        AvaliacaoRegraNivel::query()->where('ano_lectivo_id', $anoLectivoId)->where($coluna, $regraId)->whereNotIn('nivel_academico_id', $nivelIds)->delete();
        AvaliacaoRegraNivel::query()->where('ano_lectivo_id', $anoLectivoId)->whereIn('nivel_academico_id', $nivelIds)
            ->where(fn ($q) => $q->whereNull($coluna)->orWhere($coluna, '!=', $regraId))->delete();

        $ligadas = AvaliacaoRegraNivel::query()->where('ano_lectivo_id', $anoLectivoId)->where($coluna, $regraId)->pluck('nivel_academico_id')->all();

        foreach (array_diff($nivelIds, $ligadas) as $nivelId) {
            AvaliacaoRegraNivel::create(['ano_lectivo_id' => $anoLectivoId, 'nivel_academico_id' => $nivelId, $coluna => $regraId]);
        }
    }
}
