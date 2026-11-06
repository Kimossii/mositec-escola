<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\CompetenciaDTO;
use Modules\Avaliacao\DTO\CompetenciasDaAreaDTO;
use Modules\Avaliacao\Models\AvaliacaoCompetencia;
use Modules\Avaliacao\Models\AvaliacaoCompetenciaRegisto;
use Modules\Core\Traits\LogService;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Turma\Models\NivelAcademico;

/**
 * Guarda, pela ordem recebida, as competências de uma área (disciplina) para uma classe de Creche ou Pré-Escolar, ou para
 * todas (`nivelId` nulo). As que têm `id` mantêm-se (os registos já feitos continuam ligados a elas); uma competência já
 * avaliada não se remove, desactiva-se.
 */
class GuardarCompetenciasAction
{
    use LogService;

    private const MAXIMO = 30;

    public function executar(CompetenciasDaAreaDTO $dto): void
    {
        $this->comLog('Guardar competências', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            Disciplina::query()->findOrFail($dto->disciplina_id);
            $this->validar($dto);

            DB::transaction(function () use ($dto) {
                $existentes = AvaliacaoCompetencia::query()->where('disciplina_id', $dto->disciplina_id)->where('nivel_academico_id', $dto->nivel_academico_id)->get()->keyBy('id');
                $manter = array_filter(array_map(fn (CompetenciaDTO $c) => $c->id, $dto->competencias));

                foreach ($existentes->except($manter) as $removida) {
                    if (AvaliacaoCompetenciaRegisto::query()->where('competencia_id', $removida->id)->exists()) {
                        throw ValidationException::withMessages(['competencias' => "A competência «{$removida->descricao}» já foi avaliada: desactive-a em vez de a remover."]);
                    }
                    $removida->delete();
                }

                foreach ($dto->competencias as $i => $competencia) {
                    $campos = ['descricao' => trim($competencia->descricao), 'ordem' => $i + 1, 'ativa' => $competencia->ativa];

                    if ($competencia->id !== null) {
                        $existentes[$competencia->id]->update($campos);
                    } else {
                        AvaliacaoCompetencia::create([...$campos, 'disciplina_id' => $dto->disciplina_id, 'nivel_academico_id' => $dto->nivel_academico_id]);
                    }
                }
            });
        });
    }

    private function validar(CompetenciasDaAreaDTO $dto): void
    {
        if ($dto->nivel_academico_id !== null) {
            $nivel = NivelAcademico::query()->findOrFail($dto->nivel_academico_id);

            if (! in_array($nivel->etapa_ensino, [EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::PRE_ESCOLAR], true)) {
                throw ValidationException::withMessages(['nivel_academico_id' => 'As competências só se aplicam à Creche e ao Pré-Escolar.']);
            }
        }

        if (count($dto->competencias) > self::MAXIMO) {
            throw ValidationException::withMessages(['competencias' => 'Uma área tem no máximo '.self::MAXIMO.' competências.']);
        }

        $vistas = [];
        foreach ($dto->competencias as $competencia) {
            $descricao = trim($competencia->descricao);

            if ($descricao === '' || mb_strlen($descricao) > 200) {
                throw ValidationException::withMessages(['competencias' => 'Cada competência precisa de uma descrição até 200 caracteres.']);
            }

            if (in_array(mb_strtolower($descricao), $vistas, true)) {
                throw ValidationException::withMessages(['competencias' => 'As competências não podem repetir a descrição.']);
            }
            $vistas[] = mb_strtolower($descricao);
        }
    }
}
