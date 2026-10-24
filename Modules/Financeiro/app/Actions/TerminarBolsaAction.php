<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Enums\EstadoBolsa;
use Modules\Financeiro\Models\Bolsa;

/** Termina uma bolsa: deixa de se aplicar a propinas geradas daí em diante; as que já nasceram com desconto não mudam. */
class TerminarBolsaAction
{
    public function executar(Bolsa $bolsa, string $motivo): Bolsa
    {
        return DB::transaction(function () use ($bolsa, $motivo) {
            $bloqueada = Bolsa::query()->whereKey($bolsa->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoBolsa::ACTIVA) {
                throw ValidationException::withMessages(['bolsa' => 'A bolsa já está terminada.']);
            }

            if (mb_strlen(trim($motivo)) < 5) {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo (mínimo 5 caracteres).']);
            }

            $bloqueada->update(['estado' => EstadoBolsa::TERMINADA, 'motivo_termino' => trim($motivo)]);

            return $bloqueada->fresh();
        });
    }
}
