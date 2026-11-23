<?php

namespace Modules\Frequencia\Services;

use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Contracts\ConsultaChamadasPendentes;
use Modules\Core\Contracts\ConsultaDocenciaParaHorario;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Frequencia\DTO\AulaPrevista;
use Modules\Frequencia\Models\Aula;
use Modules\Turma\Models\Turma;

/**
 * As aulas previstas dos últimos 7 dias (até hoje) sem aula guardada. Uma aula marcada como não leccionada já foi
 * tratada: conta como feita. Previstas menos registadas, sem tabela própria (desenho D2).
 */
class ChamadasPorFazerService implements ConsultaChamadasPendentes
{
    public const DIAS = 7;

    public function __construct(
        private AulasPrevistasService $previstas,
        private ConsultaDocenciaParaHorario $docencia,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function doUtilizador(int $utilizadorId): int
    {
        $hoje = $this->relogio->hoje();
        $de = $hoje->subDays(self::DIAS - 1);
        // Pelo período de cada atribuição, não pelas de hoje: um substituto que começou ontem não deve as aulas de antes,
        // e quem deixou a turma ainda deve as chamadas do seu tempo.
        $pares = $this->docencia->paresDoUtilizadorNoIntervalo($utilizadorId, $de->toDateString(), $hoje->toDateString());

        return count($this->semChamada($this->previstas->dosDocentesNoPeriodo($pares, $de, $hoje)));
    }

    public function daEscola(): int
    {
        return count($this->previstasSemChamada(null));
    }

    /**
     * @param  int|null  $turmaId  uma turma, ou todas as activas do ano lectivo activo
     * @return list<AulaPrevista>
     */
    public function previstasSemChamada(?int $turmaId): array
    {
        $hoje = $this->relogio->hoje();
        $ids = $turmaId !== null ? [$turmaId] : $this->turmasDoAnoActivo();

        return $this->semChamada($this->previstas->dasTurmas($ids, $hoje->subDays(self::DIAS - 1), $hoje));
    }

    /** @return list<int> */
    private function turmasDoAnoActivo(): array
    {
        $ano = AnoLectivo::current();

        return $ano === null ? [] : Turma::query()->where('ano_lectivo_id', $ano->id)->where('estado', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<AulaPrevista>  $previstas
     * @return list<AulaPrevista>
     */
    private function semChamada(array $previstas): array
    {
        if ($previstas === []) {
            return [];
        }

        $datas = array_column($previstas, 'data');
        $feitas = Aula::query()
            ->whereIn('turma_id', array_unique(array_column($previstas, 'turma_id')))
            ->whereDate('data', '>=', min($datas))
            ->whereDate('data', '<=', max($datas))
            ->get(['turma_id', 'data', 'horario_id'])
            ->mapWithKeys(fn (Aula $a) => [$this->chave($a->turma_id, $a->data->toDateString(), $a->horario_id) => true]);

        return array_values(array_filter($previstas, fn (AulaPrevista $p) => ! isset($feitas[$this->chave($p->turma_id, $p->data, $p->horario_id)])));
    }

    private function chave(int $turmaId, string $data, ?int $horarioId): string
    {
        return $turmaId . '|' . $data . '|' . ($horarioId ?? 'dia');
    }
}
