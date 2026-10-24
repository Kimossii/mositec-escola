<?php

namespace Modules\AnoLectivo\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

class AnoLectivoConsultaService
{
    public function listar(): Collection
    {
        return AnoLectivo::orderByDesc('data_inicio')->get();
    }

    /**
     * Matrículas activas e turmas de cada ano lectivo, para a lista (duas consultas agrupadas, sem N+1).
     *
     * @return array<int, array{matriculas: int, turmas: int}> indexado pelo id do ano lectivo
     */
    public function contagens(): array
    {
        $matriculas = Matricula::query()->where('estado', EstadoMatriculaEnum::ACTIVA->value)
            ->selectRaw('ano_lectivo_id, COUNT(*) as n')->groupBy('ano_lectivo_id')->pluck('n', 'ano_lectivo_id');
        $turmas = Turma::query()->selectRaw('ano_lectivo_id, COUNT(*) as n')->groupBy('ano_lectivo_id')->pluck('n', 'ano_lectivo_id');

        return $matriculas->keys()->merge($turmas->keys())->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => ['matriculas' => (int) ($matriculas[$id] ?? 0), 'turmas' => (int) ($turmas[$id] ?? 0)]])->all();
    }

    public function comRelacoes(AnoLectivo $anoLectivo): AnoLectivo
    {
        return $anoLectivo->load(['periodos', 'eventosCalendario']);
    }
}
