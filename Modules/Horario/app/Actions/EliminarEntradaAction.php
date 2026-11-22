<?php

namespace Modules\Horario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Services\ValidadorGrelhaService;
use Modules\Turma\Models\Turma;

/** Só se elimina uma entrada cuja vigência ainda não começou; as outras encerram-se (histórico). */
class EliminarEntradaAction
{
    use LogService;

    public function __construct(
        private ValidadorGrelhaService $validador,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executar(GrelhaEntrada $entrada): void
    {
        $this->comLog('Eliminar entrada da grelha de horários', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($entrada));
    }

    private function executarSemRegisto(GrelhaEntrada $entrada): void
    {
        DB::transaction(function () use ($entrada) {
            Turma::query()->whereKey($entrada->turma_id)->lockForUpdate()->first();
            $this->validador->exigirAnoAberto((int) $entrada->turma_id);

            $actual = GrelhaEntrada::query()->findOrFail($entrada->getKey());

            if ($actual->vigencia_inicio->toDateString() <= $this->relogio->hoje()->toDateString()) {
                throw ValidationException::withMessages(['entrada' => 'Só se elimina uma entrada que ainda não começou. Encerre-a para manter o histórico.']);
            }

            $actual->delete();
        });
    }
}
