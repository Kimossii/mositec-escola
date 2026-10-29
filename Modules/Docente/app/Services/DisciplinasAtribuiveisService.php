<?php

namespace Modules\Docente\Services;

use Illuminate\Support\Collection;
use Modules\Disciplina\Models\Disciplina;
use Modules\Matricula\Actions\InscreverDisciplinasAutomaticamenteAction;
use Modules\Turma\Models\Turma;

/**
 * As disciplinas que se podem atribuir a uma turma: as do plano curricular que o sistema já resolve
 * para ela na matrícula (um só plano), só linhas activas e sem repetições.
 */
class DisciplinasAtribuiveisService
{
    public function __construct(private InscreverDisciplinasAutomaticamenteAction $resolvedor)
    {
    }

    /** @return Collection<int, Disciplina> */
    public function daTurma(Turma $turma): Collection
    {
        $plano = $this->resolvedor->resolverPlanoCurricular($turma);

        if ($plano === null) {
            return new Collection();
        }

        $ids = $plano->disciplinas
            ->filter(fn ($linha) => (int) $linha->estado === 1)
            ->pluck('disciplina_id')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new Collection();
        }

        return Disciplina::query()->whereIn('id', $ids)->orderBy('nome')->get();
    }

    /**
     * Linhas activas do plano da turma, uma por disciplina: `obrigatoria` é verdadeira se alguma linha da
     * disciplina o for. Sem plano, lista vazia. Não consulta o catálogo (para varrer muitas turmas).
     *
     * @return list<array{disciplina_id: int, obrigatoria: bool}>
     */
    public function linhasDoPlano(Turma $turma): array
    {
        $plano = $this->resolvedor->resolverPlanoCurricular($turma);

        if ($plano === null) {
            return [];
        }

        return $plano->disciplinas
            ->filter(fn ($linha) => (int) $linha->estado === 1)
            ->groupBy('disciplina_id')
            ->map(fn ($linhas, $disciplinaId) => [
                'disciplina_id' => (int) $disciplinaId,
                'obrigatoria' => $linhas->contains(fn ($l) => (bool) $l->obrigatoria),
            ])
            ->values()
            ->all();
    }
}
