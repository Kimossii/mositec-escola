<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\ProlongarPrazoDTO;
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

    public function executar(ProlongarPrazoDTO $dto): AvaliacaoPrazoExcepcao
    {
        return $this->comLog('Prolongar prazo de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            if (! Gate::allows('nota.prolongar-prazo')) {
                throw new AuthorizationException('Sem permissão para prolongar prazos.');
            }

            if (trim($dto->motivo) === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo do prolongamento.']);
            }

            $prazo = AvaliacaoPrazo::query()->with(['regra', 'regraQualitativa'])->findOrFail($dto->prazo_id);
            $actual = $this->prazos->dataLimite($prazo->regra ?? $prazo->regraQualitativa, $prazo->periodo_id, $prazo->tipo, $dto->turma_id ?? 0, $dto->disciplina_id ?? 0, $dto->docente_id);

            if ($actual->greaterThanOrEqualTo($dto->data_limite)) {
                throw ValidationException::withMessages(['data_limite' => 'A nova data tem de ser posterior ao prazo em vigor.']);
            }

            return $prazo->excepcoes()->create([
                'turma_id' => $dto->turma_id,
                'disciplina_id' => $dto->disciplina_id,
                'docente_id' => $dto->docente_id,
                'data_limite' => $dto->data_limite,
                'motivo' => $dto->motivo,
            ]);
        });
    }
}
