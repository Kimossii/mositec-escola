<?php

namespace Modules\Docente\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Turma\Models\Turma;

/**
 * Regra 10 do desenho: só se elimina uma atribuição ainda agendada; o resto é histórico e encerra-se. Como nunca
 * começou, não tem valor histórico e apaga-se de vez (senão prenderia o docente pela FK para `docentes`).
 */
class EliminarAtribuicaoAction
{
    use LogService;

    public function __construct(
        private ValidadorAtribuicaoDocenteService $validador,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(AtribuicaoDocente $atribuicao): void
    {
        $this->comLog('Eliminar atribuição docente', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($atribuicao));
    }

    private function executarSemRegisto(AtribuicaoDocente $atribuicao): void
    {
        DB::transaction(function () use ($atribuicao) {
            Turma::query()->whereKey($atribuicao->turma_id)->lockForUpdate()->first();
            $this->validador->exigirAnoAberto((int) $atribuicao->turma_id);

            $actual = AtribuicaoDocente::query()->findOrFail($atribuicao->getKey());

            if ($actual->estadoNaData($this->relogio->hoje()) !== 'agendada') {
                throw ValidationException::withMessages(['atribuicao' => 'Uma atribuição já iniciada não se elimina; encerre-a.']);
            }

            $actual->forceDelete();
        });
    }
}
