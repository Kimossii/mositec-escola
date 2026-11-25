<?php

namespace Modules\Horario\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Horario\DTO\GrelhaEntradaDTO;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Services\ValidadorGrelhaService;
use Modules\Horario\Support\SobreposicaoDeGrelha;
use Modules\Turma\Models\Turma;

/** Altera uma entrada sem perder o histórico: fecha a actual na véspera da nova vigência e cria a nova versão. */
class AlterarEntradaAction
{
    use LogService;

    public function __construct(
        private ValidadorGrelhaService $validador,
        private CriarEntradaAction $criar,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executar(GrelhaEntrada $entrada, GrelhaEntradaDTO $novo): GrelhaEntrada
    {
        return $this->comLog('Alterar entrada da grelha de horários', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($entrada, $novo));
    }

    private function executarSemRegisto(GrelhaEntrada $entrada, GrelhaEntradaDTO $novo): GrelhaEntrada
    {
        try {
            return DB::transaction(function () use ($entrada, $novo) {
                Turma::query()->whereKey($entrada->turma_id)->lockForUpdate()->first();
                $actual = GrelhaEntrada::query()->findOrFail($entrada->getKey());

                $this->exigirAlteravel($actual, $novo);

                $turma = $this->validador->validar($novo, $actual);

                $actual->update(['vigencia_fim' => CarbonImmutable::parse($novo->vigencia_inicio)->subDay()->toDateString()]);

                return $this->criar->gravar($turma->ano_lectivo_id, $novo);
            });
        } catch (QueryException $e) {
            throw SobreposicaoDeGrelha::comoErroDeValidacao($e) ?? $e;
        }
    }

    private function exigirAlteravel(GrelhaEntrada $actual, GrelhaEntradaDTO $novo): void
    {
        if ($novo->turma_id !== (int) $actual->turma_id) {
            throw ValidationException::withMessages(['turma_id' => 'Não se muda a turma de uma entrada; crie uma nova na outra turma.']);
        }

        // Só se altera a versão em vigor (sem fim): uma que já tem fim foi alterada ou encerrada, e alterá-la de novo
        // alargava-lhe a vigência ou deixava duas versões paralelas.
        if ($actual->vigencia_fim !== null) {
            throw ValidationException::withMessages(['entrada' => 'Esta entrada já tem um fim definido (foi alterada ou encerrada). Altere a versão em vigor ou crie uma nova.']);
        }

        if ($novo->vigencia_inicio < $this->relogio->hoje()->toDateString()) {
            throw ValidationException::withMessages(['vigencia_inicio' => 'A nova versão só pode começar hoje ou mais tarde; o passado não se reescreve.']);
        }

        if ($novo->vigencia_inicio <= $actual->vigencia_inicio->toDateString()) {
            throw ValidationException::withMessages(['vigencia_inicio' => 'A nova versão tem de começar depois da actual (' . $actual->vigencia_inicio->format('d/m/Y') . '). Para corrigir um engano, elimine a entrada e crie outra.']);
        }
    }
}
