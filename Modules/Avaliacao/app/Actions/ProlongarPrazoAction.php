<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoPrazoExcepcao;
use Modules\Avaliacao\Services\PrazoService;
use Modules\Core\Traits\LogService;

/**
 * Abre uma excepção ao prazo normal para um caso concreto. Quem pode é decidido só pela permissão
 * `nota.prolongar-prazo` (perfil ou utilizador); exige motivo e uma data posterior ao prazo em vigor.
 */
class ProlongarPrazoAction
{
    use LogService;

    public function __construct(private PrazoService $prazos)
    {
    }

    public function executar(int $prazoId, string $novaData, string $motivo, ?int $turmaId = null, ?int $disciplinaId = null, ?int $docenteId = null): AvaliacaoPrazoExcepcao
    {
        return $this->comLog('Prolongar prazo de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($prazoId, $novaData, $motivo, $turmaId, $disciplinaId, $docenteId) {
            if (! Gate::allows('nota.prolongar-prazo')) {
                throw new AuthorizationException('Sem permissão para prolongar prazos.');
            }

            if (trim($motivo) === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo do prolongamento.']);
            }

            $prazo = AvaliacaoPrazo::query()->with('regra')->findOrFail($prazoId);
            $actual = $this->prazos->dataLimite($prazo->regra, $prazo->periodo_id, $prazo->tipo, $turmaId ?? 0, $disciplinaId ?? 0, $docenteId);

            if ($actual->greaterThanOrEqualTo($novaData)) {
                throw ValidationException::withMessages(['data_limite' => 'A nova data tem de ser posterior ao prazo em vigor.']);
            }

            return $prazo->excepcoes()->create([
                'turma_id' => $turmaId,
                'disciplina_id' => $disciplinaId,
                'docente_id' => $docenteId,
                'data_limite' => $novaData,
                'motivo' => $motivo,
            ]);
        });
    }
}
