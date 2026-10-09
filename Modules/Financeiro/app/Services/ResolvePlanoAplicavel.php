<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Support\CalendarioDePlano;
use Modules\Financeiro\Support\Precedencia;
use Modules\Financeiro\Support\ResultadoResolucaoPlano;
use Modules\Turma\Models\Turma;

/**
 * Ponto único que decide que plano de propina se aplica a uma turma (e, opcionalmente, a uma
 * competência). Só entram planos activos do ano lectivo da turma. Ganha o de maior
 * Precedencia::rank (turma > nº de dimensões > curso > nível > turno); empate = conflito.
 */
class ResolvePlanoAplicavel
{
    /**
     * @param  array{ano: int, mes: int}|null  $competencia  null = ignora o tempo
     */
    public function paraTurma(Turma $turma, ?array $competencia = null): ResultadoResolucaoPlano
    {
        $candidatos = [];

        $planos = PlanoPropina::query()
            ->activos()
            ->where('ano_lectivo_id', $turma->ano_lectivo_id)
            ->with(['alvos.nivelAcademico', 'alvos.turno', 'alvos.turma', 'anoLectivo'])
            ->get();

        foreach ($planos as $plano) {
            if ($competencia !== null && ! CalendarioDePlano::contem($plano->competencias(), $competencia['ano'], $competencia['mes'])) {
                continue;
            }

            $rank = $this->melhorRank($plano, $turma);

            if ($rank !== null) {
                $candidatos[] = ['plano' => $plano, 'rank' => $rank];
            }
        }

        if ($candidatos === []) {
            return ResultadoResolucaoPlano::semPlano();
        }

        $maximo = $candidatos[0]['rank'];
        foreach ($candidatos as $candidato) {
            if (($candidato['rank'] <=> $maximo) > 0) {
                $maximo = $candidato['rank'];
            }
        }

        $topo = array_values(array_filter($candidatos, fn (array $c) => ($c['rank'] <=> $maximo) === 0));

        if (count($topo) === 1) {
            return ResultadoResolucaoPlano::aplicavel($topo[0]['plano']);
        }

        return ResultadoResolucaoPlano::conflito(array_map(fn (array $c) => $c['plano'], $topo));
    }

    /**
     * Melhor rank entre os alvos do plano que casam com a turma; null se nenhum casa.
     * Plano sem alvos = plano geral (rank do alvo vazio).
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function melhorRank(PlanoPropina $plano, Turma $turma): ?array
    {
        if ($plano->alvos->isEmpty()) {
            return Precedencia::rank([]);
        }

        $melhor = null;

        foreach ($plano->alvos as $alvo) {
            // Alvo cujo nível/turno/turma foi eliminado: nunca casa (o plano não passa a "geral").
            if ($alvo->obsoleto()) {
                continue;
            }

            if (! $this->casa($alvo->nivel_academico_id, $turma->nivel_academico_id)
                || ! $this->casa($alvo->curso_id, $turma->curso_id)
                || ! $this->casa($alvo->turno_id, $turma->turno_id)
                || ! $this->casa($alvo->turma_id, $turma->id)) {
                continue;
            }

            $rank = Precedencia::rank($alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id']));

            if ($melhor === null || ($rank <=> $melhor) > 0) {
                $melhor = $rank;
            }
        }

        return $melhor;
    }

    /**
     * Um campo do alvo não definido casa sempre; definido casa só se a turma tiver o mesmo
     * valor (uma turma sem esse campo nunca casa um alvo que o exige).
     */
    private function casa(?int $doAlvo, mixed $daTurma): bool
    {
        if ($doAlvo === null) {
            return true;
        }

        return $daTurma !== null && (int) $daTurma === $doAlvo;
    }
}
