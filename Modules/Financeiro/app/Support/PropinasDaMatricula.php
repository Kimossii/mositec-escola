<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Models\Propina;
use Modules\Matricula\Contracts\DependenciasDaMatricula;
use Modules\Matricula\Models\Matricula;

/**
 * Q8 (§8.1 do plano de fases): as propinas são geradas para a turma (plano aplicável), o ano lectivo e a partir do
 * mês da matrícula. Com propinas activas (Em Aberto, Parcialmente Paga ou Paga), mudar turma ou ano lectivo
 * directamente deixaria propinas de outra turma/plano/ano (L10), e mudar o mês tornaria a elegibilidade falsa.
 * A transferência segue o fluxo próprio: estado Transferida (cancela as futuras sem pagamentos, mantém dívidas) +
 * nova matrícula na turma de destino, que gera pelo seu plano.
 */
class PropinasDaMatricula implements DependenciasDaMatricula
{
    public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string
    {
        $mudaTurmaOuAno = $turmaNovaId !== (int) $matricula->turma_id || $anoLectivoNovoId !== (int) $matricula->ano_lectivo_id;
        $mudaMes = substr($dataMatriculaNova, 0, 7) !== $matricula->data_matricula?->format('Y-m');

        if (! $mudaTurmaOuAno && ! $mudaMes) {
            return null;
        }

        $activas = Propina::query()->activas()->where('matricula_id', $matricula->id)->count();

        if ($activas === 0) {
            return null;
        }

        if ($mudaTurmaOuAno) {
            return "Esta matrícula já tem {$activas} propina(s) activa(s) geradas para a turma e o ano lectivo actuais: não é possível mudá-los directamente. "
                . 'Para mudar de turma, altere o estado da matrícula para Transferida (as propinas futuras sem pagamentos são canceladas e as dívidas mantêm-se) '
                . 'e crie uma nova matrícula na turma de destino. Se as propinas foram geradas por engano, cancele-as primeiro em Financeiro → Propinas.';
        }

        return "Esta matrícula já tem {$activas} propina(s) activa(s) calculadas a partir do mês da matrícula: só pode corrigir o dia dentro do mesmo mês. "
            . 'Para mudar o mês, cancele primeiro as propinas desta matrícula em Financeiro → Propinas e gere-as de novo depois da correcção.';
    }
}
