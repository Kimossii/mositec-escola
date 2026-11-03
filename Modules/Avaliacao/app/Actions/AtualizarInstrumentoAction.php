<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Core\Traits\LogService;

/** Só o nome e a obrigatoriedade mudam; turma, disciplina, trimestre e tipo são definitivos. */
class AtualizarInstrumentoAction
{
    use LogService;

    public function executar(int $instrumentoId, string $nome, bool $obrigatorio): AvaliacaoInstrumento
    {
        return $this->comLog('Actualizar instrumento de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($instrumentoId, $nome, $obrigatorio) {
            if (trim($nome) === '') {
                throw ValidationException::withMessages(['nome' => 'Indique o nome da avaliação.']);
            }

            $instrumento = AvaliacaoInstrumento::query()->findOrFail($instrumentoId);
            $instrumento->update(['nome' => $nome, 'obrigatorio' => $obrigatorio]);

            return $instrumento;
        });
    }
}
