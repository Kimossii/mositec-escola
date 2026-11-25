<?php

namespace Modules\Horario\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Contracts\ConsultaDocenciaParaHorario;
use Modules\Core\Enums\TipoHorarioEnum;
use Modules\Core\Models\Horario;
use Modules\Docente\Services\DisciplinasAtribuiveisService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\TurnoHorario;

/**
 * Leitura da grelha: por turma, por docente, por sala e a do utilizador (Área do Professor). Só consulta.
 * `EntradaVista` = {id, dia_semana, horario_id, turma_id, turma_rotulo, disciplina_id, disciplina_nome, sala_id,
 * sala_nome, vigencia_inicio, vigencia_fim, docente: ?{id, nome}, sem_docente}. O docente é o titular em vigor na
 * data pedida (sem data, hoje no fuso da escola).
 */
class ConsultaGrelhaService
{
    public function __construct(
        private ConsultaDocenciaParaHorario $docencia,
        private DisciplinasAtribuiveisService $disciplinas,
        private RelogioDoTenant $relogio,
    ) {
    }

    /**
     * @return array{tempos: list<array<string, mixed>>, entradas: list<array<string, mixed>>, disciplinas: list<array{id: int, nome: string, tem_titular: bool}>}
     */
    public function porTurma(int $turmaId, ?CarbonInterface $data = null): array
    {
        $data ??= $this->relogio->hoje();
        $turma = Turma::query()->find($turmaId);

        if ($turma === null) {
            return ['tempos' => [], 'entradas' => [], 'disciplinas' => []];
        }

        $entradas = $this->vistas(GrelhaEntrada::query()->where('turma_id', $turma->id), $data);

        return [
            'tempos' => $this->comTemposOrfaos($this->temposDoTurno($turma->turno_id), $entradas),
            'entradas' => $entradas,
            'disciplinas' => $this->disciplinas->daTurma($turma)
                ->map(fn ($d) => ['id' => (int) $d->id, 'nome' => (string) $d->nome, 'tem_titular' => $this->docencia->titularEm($turma->id, $d->id, $data) !== null])
                ->values()
                ->all(),
        ];
    }

    /** @return array{tempos: list<array<string, mixed>>, entradas: list<array<string, mixed>>} */
    public function porDocente(int $docenteId, ?CarbonInterface $data = null): array
    {
        $data ??= $this->relogio->hoje();

        return $this->dosPares($this->docencia->paresEmVigorDoDocente($docenteId, $data), $data);
    }

    /** @return array{tempos: list<array<string, mixed>>, entradas: list<array<string, mixed>>} */
    public function doUtilizador(int $utilizadorId, ?CarbonInterface $data = null): array
    {
        $data ??= $this->relogio->hoje();

        return $this->dosPares($this->docencia->paresEmVigorDoUtilizador($utilizadorId, $data), $data);
    }

    /** @return array{tempos: list<array<string, mixed>>, entradas: list<array<string, mixed>>} */
    public function porSala(int $salaId, ?CarbonInterface $data = null): array
    {
        $data ??= $this->relogio->hoje();

        return $this->comTemposUsados($this->vistas(GrelhaEntrada::query()->where('sala_id', $salaId), $data));
    }

    /** Selects do ecrã: turmas do ano (activas), salas activas e docentes activos. */
    public function opcoes(?int $anoLectivoId = null): array
    {
        $anoLectivoId ??= $this->anoLectivoPadrao();

        return [
            'turmas' => Turma::query()
                ->with(['nivelAcademico:id,nome', 'turno:id,nome', 'anoLectivo:id,nome'])
                ->where('ano_lectivo_id', $anoLectivoId)
                ->where('estado', 1)
                ->get()
                ->map(fn (Turma $t) => ['id' => $t->id, 'nome' => $t->nome, 'rotulo' => $t->rotulo(completo: true)])
                ->sortBy('rotulo', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'salas' => Sala::query()->where('estado', EstadoSala::ATIVA->value)->orderBy('nome')->get(['id', 'nome'])
                ->map(fn (Sala $s) => ['id' => $s->id, 'nome' => $s->nome])
                ->all(),
            'docentes' => $this->docencia->docentesActivos(),
        ];
    }

    public function anoLectivoPadrao(): ?int
    {
        return AnoLectivo::current()?->id
            ?? AnoLectivo::query()->orderByDesc('data_inicio')->orderByDesc('id')->value('id');
    }

    /**
     * @param  list<array{turma_id: int, disciplina_id: int}>  $pares
     * @return array{tempos: list<array<string, mixed>>, entradas: list<array<string, mixed>>}
     */
    private function dosPares(array $pares, CarbonInterface $data): array
    {
        if ($pares === []) {
            return ['tempos' => [], 'entradas' => []];
        }

        $consulta = GrelhaEntrada::query()->where(function (Builder $q) use ($pares) {
            foreach ($pares as $par) {
                $q->orWhere(fn (Builder $p) => $p->where('turma_id', $par['turma_id'])->where('disciplina_id', $par['disciplina_id']));
            }
        });

        return $this->comTemposUsados($this->vistas($consulta, $data));
    }

    /** @return list<array<string, mixed>> */
    private function temposDoTurno(?int $turnoId): array
    {
        if ($turnoId === null) {
            return [];
        }

        return TurnoHorario::query()
            ->where('turno_id', $turnoId)
            ->with('horario')
            ->orderBy('ordem')
            ->get()
            ->map(fn (TurnoHorario $th) => $this->tempo($th->horario))
            ->values()
            ->all();
    }

    /**
     * Se o turno da turma mudou, as entradas antigas ficam em tempos que já não são do turno: acrescenta-os no fim para
     * que continuem visíveis (e se possam encerrar).
     *
     * @param  list<array<string, mixed>>  $tempos
     * @param  list<array<string, mixed>>  $entradas
     * @return list<array<string, mixed>>
     */
    private function comTemposOrfaos(array $tempos, array $entradas): array
    {
        $conhecidos = array_column($tempos, 'id');
        $emFalta = array_values(array_diff(array_unique(array_column($entradas, 'horario_id')), $conhecidos));

        if ($emFalta === []) {
            return $tempos;
        }

        $orfaos = Horario::query()->whereIn('id', $emFalta)->get()
            ->map(fn (Horario $h) => $this->tempo($h))
            ->sortBy('hora_inicio')
            ->values()
            ->all();

        return [...$tempos, ...$orfaos];
    }

    /**
     * Para as vistas que cruzam turmas: só os tempos em uso, por hora de início (sem repetidos).
     *
     * @param  list<array<string, mixed>>  $entradas
     * @return array{tempos: list<array<string, mixed>>, entradas: list<array<string, mixed>>}
     */
    private function comTemposUsados(array $entradas): array
    {
        $ids = array_values(array_unique(array_column($entradas, 'horario_id')));

        $tempos = Horario::query()->whereIn('id', $ids)->get()
            ->map(fn (Horario $h) => $this->tempo($h))
            ->sortBy('hora_inicio')
            ->values()
            ->all();

        return ['tempos' => $tempos, 'entradas' => $entradas];
    }

    /** @return array<string, mixed> */
    private function tempo(Horario $h): array
    {
        return [
            'id' => $h->id,
            'nome' => $h->nome,
            'hora_inicio' => $h->hora_inicio->format('H:i'),
            'hora_fim' => $h->hora_fim->format('H:i'),
            'tipo' => $h->tipo->value,
            'e_aula' => $h->tipo === TipoHorarioEnum::TEMPO,
        ];
    }

    /**
     * @param  Builder<GrelhaEntrada>  $consulta
     * @return list<array<string, mixed>>
     */
    private function vistas(Builder $consulta, CarbonInterface $data): array
    {
        $entradas = $consulta
            ->emVigor($data)
            ->with(['turma.nivelAcademico:id,nome', 'turma.turno:id,nome', 'turma.anoLectivo:id,nome', 'disciplina:id,nome', 'sala:id,nome', 'horario'])
            ->get();

        $titulares = [];
        foreach ($entradas as $e) {
            $titulares[$e->turma_id . '-' . $e->disciplina_id] ??= $this->docencia->titularEm($e->turma_id, $e->disciplina_id, $data);
        }

        return $entradas
            ->sortBy(fn (GrelhaEntrada $e) => sprintf('%d-%s-%010d', $e->dia_semana->value, $e->horario->hora_inicio->format('H:i'), $e->id))
            ->map(function (GrelhaEntrada $e) use ($titulares) {
                $titular = $titulares[$e->turma_id . '-' . $e->disciplina_id];

                return [
                    'id' => $e->id,
                    'dia_semana' => $e->dia_semana->value,
                    'horario_id' => (int) $e->horario_id,
                    'turma_id' => (int) $e->turma_id,
                    'turma_rotulo' => (string) $e->turma?->rotulo(completo: true),
                    'disciplina_id' => (int) $e->disciplina_id,
                    'disciplina_nome' => (string) $e->disciplina?->nome,
                    'sala_id' => $e->sala_id === null ? null : (int) $e->sala_id,
                    'sala_nome' => $e->sala?->nome,
                    'vigencia_inicio' => $e->vigencia_inicio->toDateString(),
                    'vigencia_fim' => $e->vigencia_fim?->toDateString(),
                    'docente' => $titular === null ? null : ['id' => $titular['docente_id'], 'nome' => $titular['nome']],
                    'sem_docente' => $titular === null,
                ];
            })
            ->values()
            ->all();
    }
}
