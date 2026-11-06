<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\AtualizarInstrumentoDTO;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Core\Traits\LogService;

/** Só o nome e a obrigatoriedade mudam; turma, disciplina, trimestre e tipo são definitivos. */
class AtualizarInstrumentoAction
{
    use LogService;

    public function executar(AtualizarInstrumentoDTO $dto): AvaliacaoInstrumento
    {
        return $this->comLog('Actualizar instrumento de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            if (trim($dto->nome) === '') {
                throw ValidationException::withMessages(['nome' => 'Indique o nome da avaliação.']);
            }

            $instrumento = AvaliacaoInstrumento::query()->findOrFail($dto->instrumento_id);
            $instrumento->update(['nome' => $dto->nome, 'obrigatorio' => $dto->obrigatorio]);

            return $instrumento;
        });
    }
}
