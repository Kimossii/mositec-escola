<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\LancarNotaDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Services\ResultadoDisciplinaService;
use Modules\Core\Traits\LogService;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/**
 * Lança a nota do exame de recurso (NER). Só quem tem a disciplina abaixo do mínimo (6.ª a 9.ª) faz recurso. O exame de recurso
 * cria-se na primeira nota, no 3.º trimestre, e a nota segue as regras de quem pode e de prazo das restantes. Se for
 * recusada, o que se criou desfaz-se na mesma transacção.
 */
class LancarRecursoAction
{
    use LogService;

    public function __construct(private CriarInstrumentoAction $criar, private LancarNotaAction $lancar, private ResultadoDisciplinaService $resultados)
    {
    }

    public function executar(int $turmaId, int $disciplinaId, int $inscricaoDisciplinaId, string $valor, ?string $motivo = null): AvaliacaoNota
    {
        return $this->comLog('Lançar nota de recurso', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($turmaId, $disciplinaId, $inscricaoDisciplinaId, $valor, $motivo) {
            $turma = Turma::query()->findOrFail($turmaId);
            $inscricao = InscricaoDisciplina::query()->with(['matricula', 'planoCurricularDisciplina'])->findOrFail($inscricaoDisciplinaId);

            if ($inscricao->matricula->turma_id !== $turma->id || $inscricao->planoCurricularDisciplina->disciplina_id !== $disciplinaId) {
                throw ValidationException::withMessages(['inscricao_disciplina_id' => 'O aluno não está inscrito nesta disciplina e turma.']);
            }

            if (! $this->resultados->calcular($inscricao->id)->recursoNecessario) {
                throw ValidationException::withMessages(['inscricao_disciplina_id' => 'Só faz exame de recurso quem tem a disciplina abaixo da nota mínima.']);
            }

            $recurso = AvaliacaoInstrumento::query()->where('turma_id', $turma->id)->where('disciplina_id', $disciplinaId)->where('tipo', TipoInstrumento::RECURSO->value)->first()
                ?? $this->criar->executar(new InstrumentoDTO($turma->id, $disciplinaId, $this->terceiroTrimestre($turma)->id, TipoInstrumento::RECURSO, 'Exame de recurso'));

            return $this->lancar->executar(new LancarNotaDTO($recurso->id, $inscricaoDisciplinaId, $valor, $motivo));
        }));
    }

    private function terceiroTrimestre(Turma $turma): Periodo
    {
        return Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->where('numero', 3)->first()
            ?? throw ValidationException::withMessages(['turma_id' => 'O ano lectivo desta turma não tem o 3.º trimestre.']);
    }
}
