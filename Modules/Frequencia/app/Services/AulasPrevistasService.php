<?php

namespace Modules\Frequencia\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\Core\Contracts\ConsultaDocenciaParaHorario;
use Modules\Frequencia\DTO\AulaPrevista;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Horario\Enums\DiaSemana;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Turma\Models\Turma;

/**
 * As aulas previstas, calculadas na hora a partir da grelha vigente e do calendário (desenho D2). Não há aula em
 * domingo, fora do ano lectivo, nem em dia coberto por feriado ou férias; no regime «dia», o dia é previsto se a turma
 * tiver pelo menos uma entrada da grelha em vigor nesse dia da semana (D10).
 *
 * Os dados (grelha, calendário, titulares) carregam-se de uma vez para todo o intervalo, por isso o número de queries
 * não cresce com os dias.
 */
class AulasPrevistasService
{
    public function __construct(
        private RegimeDeChamada $regime,
        private ConsultaDocenciaParaHorario $docencia,
    ) {
    }

    /** @return list<AulaPrevista> por ordem de hora de início */
    public function daTurmaNoDia(int $turmaId, CarbonInterface $data): array
    {
        return $this->dasTurmas([$turmaId], $data, $data);
    }

    /**
     * As previstas de várias turmas entre duas datas, inclusivas.
     *
     * @param  list<int>  $turmaIds
     * @return list<AulaPrevista>
     */
    public function dasTurmas(array $turmaIds, CarbonInterface $de, CarbonInterface $ate): array
    {
        if ($turmaIds === []) {
            return [];
        }

        $turmas = Turma::query()->with(['nivelAcademico', 'anoLectivo'])->whereIn('id', $turmaIds)->get();

        return $this->entreDatas($turmas, $de, $ate);
    }

    /**
     * As previstas de um docente (ou utilizador) entre duas datas, inclusivas: no regime tempo só as das suas
     * (turma, disciplina); no regime dia, as de qualquer turma em que lecciona.
     *
     * @param  list<array{turma_id: int, disciplina_id: int}>  $pares
     * @return list<AulaPrevista>
     */
    public function dosDocentes(array $pares, CarbonInterface $de, CarbonInterface $ate): array
    {
        if ($pares === []) {
            return [];
        }

        $chaves = array_map(fn (array $p) => $p['turma_id'] . '-' . $p['disciplina_id'], $pares);

        return array_values(array_filter(
            $this->dasTurmas(array_values(array_unique(array_column($pares, 'turma_id'))), $de, $ate),
            fn (AulaPrevista $p) => $p->tipo === TipoAula::DIA || in_array($p->turma_id . '-' . $p->disciplina_id, $chaves, true),
        ));
    }

    /**
     * As previstas que cabiam a um docente, tendo em conta quando teve cada atribuição (e não só hoje): uma aula só
     * conta se calhou dentro do período em que ele leccionava essa turma (e, no regime tempo, essa disciplina).
     *
     * @param  list<array{turma_id: int, disciplina_id: int, inicio: string, fim: string}>  $pares
     * @return list<AulaPrevista>
     */
    public function dosDocentesNoPeriodo(array $pares, CarbonInterface $de, CarbonInterface $ate): array
    {
        if ($pares === []) {
            return [];
        }

        return array_values(array_filter(
            $this->dasTurmas(array_values(array_unique(array_column($pares, 'turma_id'))), $de, $ate),
            function (AulaPrevista $p) use ($pares) {
                foreach ($pares as $par) {
                    if ($par['turma_id'] === $p->turma_id && $par['inicio'] <= $p->data && $par['fim'] >= $p->data
                        && ($p->tipo === TipoAula::DIA || $par['disciplina_id'] === $p->disciplina_id)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * @param  iterable<Turma>  $turmas
     * @return list<AulaPrevista>
     */
    private function entreDatas(iterable $turmas, CarbonInterface $de, CarbonInterface $ate): array
    {
        $turmas = collect($turmas);
        $inicio = $de->toDateString();
        $fim = $ate->toDateString();

        $entradas = GrelhaEntrada::query()
            ->whereIn('turma_id', $turmas->pluck('id'))
            ->sobrepoe($inicio, $fim)
            ->with('horario')
            ->get()
            ->groupBy('turma_id');

        $eventos = EventoCalendario::query()
            ->whereIn('ano_lectivo_id', $turmas->pluck('ano_lectivo_id')->unique())
            ->where('estado', 1)
            ->whereIn('tipo', [TipoEventoCalendario::FERIADO->value, TipoEventoCalendario::FERIAS->value])
            ->whereDate('data_inicio', '<=', $fim)
            ->whereDate('data_fim', '>=', $inicio)
            ->get()
            ->groupBy('ano_lectivo_id');

        $titulares = [];
        $previstas = [];

        for ($dia = $de->copy()->startOfDay(); $dia->lte($ate); $dia = $dia->copy()->addDay()) {
            foreach ($turmas as $turma) {
                array_push($previstas, ...$this->doDia($turma, $dia, $entradas[$turma->id] ?? collect(), $eventos[$turma->ano_lectivo_id] ?? collect(), $titulares, $inicio, $fim));
            }
        }

        return $previstas;
    }

    /**
     * @param  Collection<int, GrelhaEntrada>  $entradasDaTurma
     * @param  Collection<int, EventoCalendario>  $eventosDoAno
     * @param  array<string, list<array{docente_id: int, inicio: string, fim: string}>>  $titulares  cache por (turma, disciplina)
     * @return list<AulaPrevista>
     */
    private function doDia(Turma $turma, CarbonInterface $data, Collection $entradasDaTurma, Collection $eventosDoAno, array &$titulares, string $de, string $ate): array
    {
        $ano = $turma->anoLectivo;
        $dia = $data->toDateString();

        if ($ano === null || $dia < $ano->data_inicio->toDateString() || $dia > $ano->data_fim->toDateString()) {
            return [];
        }

        $diaSemana = DiaSemana::fromData($data);
        if ($diaSemana === null || $eventosDoAno->contains(fn (EventoCalendario $e) => $e->data_inicio->toDateString() <= $dia && $e->data_fim->toDateString() >= $dia)) {
            return [];
        }

        $entradas = $entradasDaTurma
            ->filter(fn (GrelhaEntrada $e) => $e->dia_semana === $diaSemana
                && $e->vigencia_inicio->toDateString() <= $dia
                && ($e->vigencia_fim === null || $e->vigencia_fim->toDateString() >= $dia))
            ->sortBy(fn (GrelhaEntrada $e) => $e->horario->hora_inicio->format('H:i') . sprintf('%010d', $e->id))
            ->values();

        if ($entradas->isEmpty()) {
            return [];
        }

        $titularDe = function (int $disciplinaId) use ($turma, $dia, &$titulares, $de, $ate): ?int {
            $chave = $turma->id . '-' . $disciplinaId;
            $titulares[$chave] ??= $this->docencia->titularesNoIntervalo($turma->id, $disciplinaId, $de, $ate);

            foreach ($titulares[$chave] as $t) {
                if ($t['inicio'] <= $dia && $t['fim'] >= $dia) {
                    return $t['docente_id'];
                }
            }

            return null;
        };

        if ($this->regime->daTurma($turma) === TipoAula::DIA) {
            // Professor único: o docente do dia é o titular de qualquer das disciplinas leccionadas nesse dia.
            $docenteId = null;
            foreach ($entradas as $e) {
                $docenteId = $titularDe((int) $e->disciplina_id);
                if ($docenteId !== null) {
                    break;
                }
            }

            return [new AulaPrevista($turma->id, $dia, TipoAula::DIA, null, null, null, $docenteId, $docenteId === null)];
        }

        return $entradas->map(function (GrelhaEntrada $e) use ($turma, $dia, $titularDe) {
            $docenteId = $titularDe((int) $e->disciplina_id);

            return new AulaPrevista(
                $turma->id,
                $dia,
                TipoAula::TEMPO,
                (int) $e->horario_id,
                (int) $e->disciplina_id,
                $e->sala_id === null ? null : (int) $e->sala_id,
                $docenteId,
                $docenteId === null,
            );
        })->all();
    }
}
