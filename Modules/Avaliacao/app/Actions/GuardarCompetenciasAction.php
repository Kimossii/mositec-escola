<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    /** @param  list<array{id?: int|null, descricao: string, ativa?: bool}>  $itens */
    public function executar(int $disciplinaId, ?int $nivelId, array $itens): void
    {
        $this->comLog('Guardar competências', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($disciplinaId, $nivelId, $itens) {
            Disciplina::query()->findOrFail($disciplinaId);
            $this->validar($nivelId, $itens);

            DB::transaction(function () use ($disciplinaId, $nivelId, $itens) {
                $existentes = AvaliacaoCompetencia::query()->where('disciplina_id', $disciplinaId)->where('nivel_academico_id', $nivelId)->get()->keyBy('id');
                $manter = array_filter(array_column($itens, 'id'));

                foreach ($existentes->except($manter) as $removida) {
                    if (AvaliacaoCompetenciaRegisto::query()->where('competencia_id', $removida->id)->exists()) {
                        throw ValidationException::withMessages(['competencias' => "A competência «{$removida->descricao}» já foi avaliada: desactive-a em vez de a remover."]);
                    }
                    $removida->delete();
                }

                foreach (array_values($itens) as $i => $dados) {
                    $campos = ['descricao' => trim($dados['descricao']), 'ordem' => $i + 1, 'ativa' => (bool) ($dados['ativa'] ?? true)];

                    if (! empty($dados['id'])) {
                        $existentes[$dados['id']]->update($campos);
                    } else {
                        AvaliacaoCompetencia::create([...$campos, 'disciplina_id' => $disciplinaId, 'nivel_academico_id' => $nivelId]);
                    }
                }
            });
        });
    }

    private function validar(?int $nivelId, array $itens): void
    {
        if ($nivelId !== null) {
            $nivel = NivelAcademico::query()->findOrFail($nivelId);

            if (! in_array($nivel->etapa_ensino, [EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::PRE_ESCOLAR], true)) {
                throw ValidationException::withMessages(['nivel_academico_id' => 'As competências só se aplicam à Creche e ao Pré-Escolar.']);
            }
        }

        if (count($itens) > self::MAXIMO) {
            throw ValidationException::withMessages(['competencias' => 'Uma área tem no máximo '.self::MAXIMO.' competências.']);
        }

        $vistas = [];
        foreach ($itens as $item) {
            $descricao = trim($item['descricao'] ?? '');

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
