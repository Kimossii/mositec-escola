<?php

namespace Modules\Financeiro\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Services\CancelarPropinasFuturasDaMatricula;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;

/**
 * Q5: SÍNCRONO, corre dentro da transacção da mudança de estado da matrícula (o evento é despachado lá dentro).
 * Uma excepção aqui reverte o estado, a data de fim e o histórico da matrícula: nunca se apanha. Se alguém
 * despachar o evento fora de uma transacção, esta abre a sua. A reconciliação diária é a rede de segurança.
 */
class CancelarPropinasAoTerminarMatricula
{
    public function __construct(private CancelarPropinasFuturasDaMatricula $cancelar)
    {
    }

    public function handle(MatriculaEstadoAlterado $evento): void
    {
        $estado = EstadoMatriculaEnum::tryFrom($evento->estadoNovo);

        // Activa, Pendente e estados que o Financeiro não conhece (ex.: Trancada): nada a fazer aqui.
        if ($estado === null || ! in_array($estado, CancelarPropinasFuturasDaMatricula::ESTADOS_TERMINAIS, true)) {
            return;
        }

        DB::transaction(function () use ($evento) {
            // A Matrícula já a bloqueou nesta transacção; relê-se para usar o estado acabado de gravar.
            $matricula = Matricula::query()->whereKey($evento->matriculaId)->lockForUpdate()->first();

            if ($matricula === null) {
                return;
            }

            $this->cancelar->executar($matricula, $evento->dataEfeito, $evento->utilizadorId);
        });
    }
}
