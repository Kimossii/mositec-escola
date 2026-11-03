<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Services\DocenteDoUtilizadorService;
use Modules\Avaliacao\Services\PrazoService;
use Modules\Avaliacao\Services\RegraAvaliacaoService;
use Modules\Core\Traits\LogService;
use Modules\Turma\Models\Turma;

/**
 * Anula a nota (valor passa a nulo). Por defeito a avaliação fica fora do cálculo; quem anula pode decidir que conte
 * como zero. Só `nota.anular`, com motivo. Não confundir com zero lançado nem com obrigatória em falta depois do prazo.
 */
class AnularNotaAction
{
    use LogService;

    public function __construct(
        private PrazoService $prazos,
        private RegraAvaliacaoService $regras,
        private DocenteDoUtilizadorService $docentes,
    ) {
    }

    public function executar(int $notaId, bool $contaComoZero, string $motivo): AvaliacaoNota
    {
        return $this->comLog('Anular nota', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($notaId, $contaComoZero, $motivo) {
            if (! Gate::allows('nota.anular')) {
                throw new AuthorizationException('Sem permissão para anular avaliações.');
            }

            if (trim($motivo) === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo da anulação.']);
            }

            $nota = AvaliacaoNota::query()->with('instrumento')->whereKey($notaId)->lockForUpdate()->firstOrFail();
            $instrumento = $nota->instrumento;
            $regra = $this->regras->paraTurma(Turma::query()->findOrFail($instrumento->turma_id));
            $foraDoPrazo = $regra !== null && $this->prazos->instrumentoEncerrado($instrumento, $regra, $this->docentes->idDe(auth()->user()));

            $anterior = ['valor' => $nota->valor, 'estado' => $nota->estado];
            $estado = $contaComoZero ? EstadoNota::ANULADA_CONTA_ZERO : EstadoNota::ANULADA;

            $nota->update(['valor' => null, 'estado' => $estado]);

            $nota->historico()->create([
                'valor_anterior' => $anterior['valor'],
                'valor_novo' => null,
                'estado_anterior' => $anterior['estado'],
                'estado_novo' => $estado,
                'fora_do_prazo' => $foraDoPrazo,
                'motivo' => $motivo,
                'criado_por' => auth()->id(),
            ]);

            return $nota;
        }));
    }
}
