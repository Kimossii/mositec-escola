<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa;
use Modules\Avaliacao\Support\Calculo\RegraDoRecurso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

/** Quem da turma pode ir ao exame de recurso, pelas regras da escola (máximo de negativas e disciplinas-chave). Só consulta. */
class ElegibilidadeRecursoService
{
    public function __construct(
        private ResultadoDisciplinaService $resultados,
        private RegraDoRecurso $regraDoRecurso,
        private CalculadoraQuantitativa $calculadora,
    ) {
    }

    /**
     * @return array<int, array{reprovadas: list<int>, elegivel: bool, motivo: ?string, texto: ?string}> indexado pela matrícula
     */
    public function paraTurma(Turma $turma, AvaliacaoRegra $regra): array
    {
        $matriculas = Matricula::query()->where('turma_id', $turma->id)->where('estado', EstadoMatriculaEnum::ACTIVA)->pluck('id');
        $inscricoes = InscricaoDisciplina::query()->whereIn('matricula_id', $matriculas)->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA)
            ->with('planoCurricularDisciplina:id,disciplina_id')->get()->groupBy(fn (InscricaoDisciplina $i) => $i->planoCurricularDisciplina->disciplina_id);

        $porMatricula = [];
        foreach ($inscricoes as $disciplinaId => $daDisciplina) {
            $ids = $daDisciplina->pluck('id')->all();
            foreach ($this->resultados->calcularTurma($turma->id, $disciplinaId, $ids) as $inscricaoId => $r) {
                $matriculaId = $daDisciplina->firstWhere('id', $inscricaoId)->matricula_id;
                $porMatricula[$matriculaId][$disciplinaId] = $r->baseArredondada();
            }
        }

        $chave = $regra->disciplinasChave()->pluck('disciplinas.id')->map(fn ($id) => (int) $id)->all();
        $nomes = Disciplina::query()->whereIn('id', $inscricoes->keys())->pluck('nome', 'id')->map(fn ($n) => (string) $n)->all();
        $maximo = $regra->recurso_max_disciplinas;

        $resultado = [];
        foreach ($matriculas as $matriculaId) {
            $a = $this->regraDoRecurso->avaliar($porMatricula[$matriculaId] ?? [], (string) $regra->nota_minima, $maximo, $chave, $this->calculadora);
            $resultado[$matriculaId] = ['reprovadas' => $a['reprovadas'], 'elegivel' => $a['elegivel'], 'motivo' => $a['motivo'], 'texto' => RegraDoRecurso::descrever($a, $maximo, $nomes)];
        }

        return $resultado;
    }
}
