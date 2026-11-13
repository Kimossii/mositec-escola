<?php

namespace Modules\Turma\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

/**
 * Quando a escola retira uma etapa de ensino, saem as classes dessa etapa, mas só se nenhuma tiver informação ligada
 * (turmas, planos, alunos…). Se alguma tiver, não se mexe em nenhuma e a etapa não pode ser retirada.
 */
class RemoverNiveisDaEtapaAction
{
    use LogService;

    public function executar(Estabelecimento $estabelecimento, EtapaEnsinoEnum $etapa): void
    {
        $this->comLog('Remover classes da etapa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($estabelecimento, $etapa));
    }

    private function executarSemRegisto(Estabelecimento $estabelecimento, EtapaEnsinoEnum $etapa): void
    {
        DB::transaction(function () use ($estabelecimento, $etapa) {
            $niveis = NivelAcademico::query()->where('estabelecimento_id', $estabelecimento->id)->where('etapa_ensino', $etapa)->get();
            $emUso = $niveis->filter(fn (NivelAcademico $n) => $n->temVinculos());

            if ($emUso->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'etapas_ensino' => "Não é possível remover a etapa \"{$etapa->label()}\": as classes {$emUso->pluck('nome')->implode(', ')} já têm turmas, planos ou alunos associados.",
                ]);
            }

            $niveis->each->delete();
        });
    }
}
