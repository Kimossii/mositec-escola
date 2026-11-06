<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Enums\EstadoPauta;
use Modules\Avaliacao\Models\AvaliacaoPauta;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Turma\Models\Turma;

/** Reabre uma pauta definitiva para alterações excepcionais. Só com `nota.reabrir` e motivo; a versão aprovada fica guardada. */
class ReabrirPautaAction
{
    use LogService;

    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    public function executar(int $turmaId, string $motivo): AvaliacaoPauta
    {
        return $this->comLog('Reabrir pauta final', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($turmaId, $motivo) {
            if (! Gate::allows('nota.reabrir')) {
                throw new AuthorizationException('Sem permissão para reabrir pautas.');
            }

            if (trim($motivo) === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo da reabertura.']);
            }

            Turma::query()->findOrFail($turmaId);
            $pauta = AvaliacaoPauta::query()->where('turma_id', $turmaId)->lockForUpdate()->first();

            if ($pauta?->estado !== EstadoPauta::DEFINITIVA) {
                throw ValidationException::withMessages(['pauta' => 'Só se reabre uma pauta definitiva.']);
            }

            $pauta->update(['estado' => EstadoPauta::REABERTA, 'reaberta_por' => auth()->id(), 'reaberta_em' => $this->relogio->agora(), 'motivo_reabertura' => $motivo]);

            return $pauta;
        }));
    }
}
