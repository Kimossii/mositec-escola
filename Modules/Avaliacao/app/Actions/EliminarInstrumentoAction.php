<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Core\Traits\LogService;

/** Só se elimina um instrumento ainda sem notas. */
class EliminarInstrumentoAction
{
    use LogService;

    public function executar(int $instrumentoId): void
    {
        $this->comLog('Eliminar instrumento de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($instrumentoId) {
            $instrumento = AvaliacaoInstrumento::query()->findOrFail($instrumentoId);

            if ($instrumento->notas()->exists()) {
                throw ValidationException::withMessages(['instrumento' => 'Esta avaliação já tem notas lançadas e não pode ser eliminada.']);
            }

            $instrumento->delete();
        });
    }
}
