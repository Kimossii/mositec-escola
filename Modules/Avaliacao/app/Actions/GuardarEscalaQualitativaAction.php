<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\EscalaQualitativaDTO;
use Modules\Avaliacao\DTO\NivelDaEscalaDTO;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoEscalaNivel;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Core\Traits\LogService;

/**
 * Cria ou actualiza uma escala qualitativa e os seus níveis, pela ordem recebida (do mais baixo ao mais alto).
 * Os níveis com `id` mantêm-se (as avaliações já lançadas continuam ligadas a eles); um nível já usado não se remove.
 */
class GuardarEscalaQualitativaAction
{
    use LogService;

    public function executar(EscalaQualitativaDTO $dto): AvaliacaoEscala
    {
        return $this->comLog('Guardar escala qualitativa', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            $this->validar($dto);

            return DB::transaction(function () use ($dto) {
                $escala = $dto->id === null ? AvaliacaoEscala::create(['nome' => $dto->nome]) : tap(AvaliacaoEscala::query()->findOrFail($dto->id))->update(['nome' => $dto->nome]);

                $existentes = $escala->niveis()->get()->keyBy('id');
                $manter = array_filter(array_map(fn (NivelDaEscalaDTO $n) => $n->id, $dto->niveis));

                foreach ($existentes->except($manter) as $removido) {
                    if (AvaliacaoQualitativa::query()->where('escala_nivel_id', $removido->id)->exists()) {
                        throw ValidationException::withMessages(['niveis' => "O nível «{$removido->designacao}» já foi usado em avaliações e não pode ser removido."]);
                    }
                    $removido->delete();
                }

                // Duas fases por causa da unicidade da ordem: primeiro afastar, depois fixar.
                foreach ($existentes->only($manter) as $nivel) {
                    $nivel->update(['ordem' => 1000 + $nivel->id]);
                }

                foreach ($dto->niveis as $i => $nivel) {
                    $campos = ['codigo' => trim($nivel->codigo), 'designacao' => trim($nivel->designacao), 'ordem' => $i + 1];
                    $nivel->id !== null ? $existentes[$nivel->id]->update($campos) : $escala->niveis()->create($campos);
                }

                return $escala->load('niveis');
            });
        });
    }

    private function validar(EscalaQualitativaDTO $dto): void
    {
        if (trim($dto->nome) === '') {
            throw ValidationException::withMessages(['nome' => 'Indique o nome da escala.']);
        }

        if (AvaliacaoEscala::query()->where('nome', trim($dto->nome))->when($dto->id, fn ($q) => $q->whereKeyNot($dto->id))->exists()) {
            throw ValidationException::withMessages(['nome' => 'Já existe uma escala com este nome.']);
        }

        if (count($dto->niveis) < 2 || count($dto->niveis) > 12) {
            throw ValidationException::withMessages(['niveis' => 'Uma escala tem entre 2 e 12 níveis.']);
        }

        $codigos = [];
        foreach ($dto->niveis as $nivel) {
            if (trim($nivel->codigo) === '' || trim($nivel->designacao) === '') {
                throw ValidationException::withMessages(['niveis' => 'Cada nível precisa de código e designação.']);
            }
            $codigos[] = mb_strtolower(trim($nivel->codigo));

            if ($nivel->id !== null && $dto->id !== null && ! AvaliacaoEscalaNivel::query()->whereKey($nivel->id)->where('escala_id', $dto->id)->exists()) {
                throw ValidationException::withMessages(['niveis' => 'Nível inválido para esta escala.']);
            }
        }

        if (count(array_unique($codigos)) !== count($codigos)) {
            throw ValidationException::withMessages(['niveis' => 'Os códigos dos níveis têm de ser diferentes.']);
        }
    }
}
