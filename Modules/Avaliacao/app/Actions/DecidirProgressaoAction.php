<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\DecidirProgressaoDTO;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\Progressao;
use Modules\Avaliacao\Models\AvaliacaoProgressao;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Core\Traits\LogService;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

/**
 * Decide a progressão de uma criança (Transita ou Não transita) quando a regra da classe é «decidida criança a criança».
 * Só com `nota.aprovar`; «Não transita» exige motivo. A decisão fica gravada, mas só se formaliza com a aprovação da pauta.
 */
class DecidirProgressaoAction
{
    use LogService;

    public function __construct(private PautaFinalService $pautas)
    {
    }

    public function executar(DecidirProgressaoDTO $dto): AvaliacaoProgressao
    {
        return $this->comLog('Decidir progressão', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($dto) {
            if (! Gate::allows('nota.aprovar')) {
                throw new AuthorizationException('Sem permissão para decidir a progressão.');
            }

            if (! in_array($dto->decisao, [Decisao::TRANSITA, Decisao::NAO_TRANSITA], true)) {
                throw ValidationException::withMessages(['decisao' => 'Na Creche e no Pré-Escolar a decisão é Transita ou Não transita.']);
            }

            if ($dto->decisao === Decisao::NAO_TRANSITA && trim((string) $dto->motivo) === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo de a criança não transitar.']);
            }

            $turma = Turma::query()->findOrFail($dto->turma_id);
            $matricula = Matricula::query()->where('turma_id', $turma->id)->findOrFail($dto->matricula_id);
            $this->pautas->exigirAberta($turma->id);

            $regra = AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('nivel_academico_id', $turma->nivel_academico_id)->first();
            if ($regra === null || $regra->progressao !== Progressao::MANUAL) {
                throw ValidationException::withMessages(['matricula_id' => 'A progressão desta classe é automática: não se decide criança a criança.']);
            }

            return AvaliacaoProgressao::updateOrCreate(['matricula_id' => $matricula->id], ['decisao' => $dto->decisao, 'motivo' => $dto->decisao === Decisao::NAO_TRANSITA ? trim((string) $dto->motivo) : null]);
        }));
    }
}
