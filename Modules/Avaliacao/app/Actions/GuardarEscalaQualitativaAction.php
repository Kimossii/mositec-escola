<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    /** @param  list<array{id?: int|null, codigo: string, designacao: string}>  $niveis */
    public function executar(?int $escalaId, string $nome, array $niveis): AvaliacaoEscala
    {
        return $this->comLog('Guardar escala qualitativa', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($escalaId, $nome, $niveis) {
            $this->validar($escalaId, $nome, $niveis);

            return DB::transaction(function () use ($escalaId, $nome, $niveis) {
                $escala = $escalaId === null ? AvaliacaoEscala::create(['nome' => $nome]) : tap(AvaliacaoEscala::query()->findOrFail($escalaId))->update(['nome' => $nome]);

                $existentes = $escala->niveis()->get()->keyBy('id');
                $manter = array_filter(array_column($niveis, 'id'));

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

                foreach (array_values($niveis) as $i => $dados) {
                    $campos = ['codigo' => trim($dados['codigo']), 'designacao' => trim($dados['designacao']), 'ordem' => $i + 1];
                    ! empty($dados['id']) ? $existentes[$dados['id']]->update($campos) : $escala->niveis()->create($campos);
                }

                return $escala->load('niveis');
            });
        });
    }

    private function validar(?int $escalaId, string $nome, array $niveis): void
    {
        if (trim($nome) === '') {
            throw ValidationException::withMessages(['nome' => 'Indique o nome da escala.']);
        }

        if (AvaliacaoEscala::query()->where('nome', trim($nome))->when($escalaId, fn ($q) => $q->whereKeyNot($escalaId))->exists()) {
            throw ValidationException::withMessages(['nome' => 'Já existe uma escala com este nome.']);
        }

        if (count($niveis) < 2 || count($niveis) > 12) {
            throw ValidationException::withMessages(['niveis' => 'Uma escala tem entre 2 e 12 níveis.']);
        }

        $codigos = [];
        foreach ($niveis as $n) {
            if (trim($n['codigo'] ?? '') === '' || trim($n['designacao'] ?? '') === '') {
                throw ValidationException::withMessages(['niveis' => 'Cada nível precisa de código e designação.']);
            }
            $codigos[] = mb_strtolower(trim($n['codigo']));

            if (! empty($n['id']) && $escalaId !== null && ! AvaliacaoEscalaNivel::query()->whereKey($n['id'])->where('escala_id', $escalaId)->exists()) {
                throw ValidationException::withMessages(['niveis' => 'Nível inválido para esta escala.']);
            }
        }

        if (count(array_unique($codigos)) !== count($codigos)) {
            throw ValidationException::withMessages(['niveis' => 'Os códigos dos níveis têm de ser diferentes.']);
        }
    }
}
