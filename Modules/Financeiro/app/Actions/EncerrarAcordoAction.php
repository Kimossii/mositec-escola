<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Financeiro\Models\Acordo;

/**
 * Cancela (a escola desiste) ou quebra (o aluno falhou) um acordo activo. Em ambos o pagamento parcial e as multas
 * voltam ao normal; o desconto já dado nas multas mantém-se. O que já foi pago nunca se desfaz.
 */
class EncerrarAcordoAction
{
    public function executar(Acordo $acordo, EstadoAcordo $destino, string $motivo): Acordo
    {
        return DB::transaction(function () use ($acordo, $destino, $motivo) {
            if ($destino === EstadoAcordo::ACTIVO) {
                throw ValidationException::withMessages(['acordo' => 'Destino inválido.']);
            }

            $bloqueado = Acordo::query()->whereKey($acordo->id)->lockForUpdate()->firstOrFail();

            if ($bloqueado->estado !== EstadoAcordo::ACTIVO) {
                throw ValidationException::withMessages(['acordo' => 'O acordo já está encerrado.']);
            }

            if (mb_strlen(trim($motivo)) < 5) {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo (mínimo 5 caracteres).']);
            }

            $bloqueado->update(['estado' => $destino, 'motivo_encerramento' => trim($motivo)]);

            return $bloqueado->fresh();
        });
    }
}
