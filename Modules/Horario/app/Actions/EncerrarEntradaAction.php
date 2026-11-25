<?php

namespace Modules\Horario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Services\ValidadorGrelhaService;
use Modules\Turma\Models\Turma;

/** Define `vigencia_fim` (hoje ou futura, dentro do ano). Uma entrada terminada não se reabre. */
class EncerrarEntradaAction
{
    use LogService;

    public function __construct(
        private ValidadorGrelhaService $validador,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executar(GrelhaEntrada $entrada, string $dataFim): GrelhaEntrada
    {
        return $this->comLog('Encerrar entrada da grelha de horários', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($entrada, $dataFim));
    }

    private function executarSemRegisto(GrelhaEntrada $entrada, string $dataFim): GrelhaEntrada
    {
        return DB::transaction(function () use ($entrada, $dataFim) {
            Turma::query()->whereKey($entrada->turma_id)->lockForUpdate()->first();
            $turma = $this->validador->exigirAnoAberto((int) $entrada->turma_id);

            $actual = GrelhaEntrada::query()->findOrFail($entrada->getKey());
            $hoje = $this->relogio->hoje()->toDateString();

            if ($actual->vigencia_fim !== null && $actual->vigencia_fim->toDateString() < $hoje) {
                throw ValidationException::withMessages(['entrada' => 'Uma entrada terminada não se altera; crie uma nova.']);
            }

            if ($dataFim < $actual->vigencia_inicio->toDateString()) {
                throw ValidationException::withMessages(['vigencia_fim' => 'A data de fim não pode ser anterior à data de início.']);
            }

            if ($dataFim < $hoje) {
                throw ValidationException::withMessages(['vigencia_fim' => 'A data de fim não pode ser anterior a hoje.']);
            }

            // Encerrar só encurta: alargar a vigência saltava a validação de conflitos.
            if ($actual->vigencia_fim !== null && $dataFim > $actual->vigencia_fim->toDateString()) {
                throw ValidationException::withMessages(['vigencia_fim' => 'A entrada já termina em ' . $actual->vigencia_fim->format('d/m/Y') . '; só se pode encurtar.']);
            }

            if ($dataFim > $turma->anoLectivo->data_fim->toDateString()) {
                throw ValidationException::withMessages(['vigencia_fim' => 'A data de fim tem de estar dentro do ano lectivo (até ' . $turma->anoLectivo->data_fim->format('d/m/Y') . ').']);
            }

            $actual->update(['vigencia_fim' => $dataFim]);

            return $actual;
        });
    }
}
