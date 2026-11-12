<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\LancarExameDTO;
use Modules\Avaliacao\DTO\LancarNotaDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Services\AcessoAMiniPautaService;
use Modules\Avaliacao\Services\ElegibilidadeRecursoService;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Services\RegraAvaliacaoService;
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

    public function __construct(
        private CriarInstrumentoAction $criar,
        private LancarNotaAction $lancar,
        private ResultadoDisciplinaService $resultados,
        private ElegibilidadeRecursoService $elegibilidade,
        private RegraAvaliacaoService $regras,
        private AcessoAMiniPautaService $acesso,
        private PautaFinalService $pautas,
    ) {
    }

    public function executar(LancarExameDTO $dto): AvaliacaoNota
    {
        return $this->comLog('Lançar nota de recurso', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($dto) {
            $this->acesso->exigirDoUtilizadorActual($dto->turma_id, $dto->disciplina_id);
            $turma = Turma::query()->findOrFail($dto->turma_id);
            $inscricao = InscricaoDisciplina::query()->with(['matricula', 'planoCurricularDisciplina'])->findOrFail($dto->inscricao_disciplina_id);

            if ($inscricao->matricula->turma_id !== $turma->id || $inscricao->planoCurricularDisciplina->disciplina_id !== $dto->disciplina_id) {
                throw ValidationException::withMessages(['inscricao_disciplina_id' => 'O aluno não está inscrito nesta disciplina e turma.']);
            }

            if (! $this->resultados->calcular($inscricao->id)->recursoNecessario) {
                throw ValidationException::withMessages(['inscricao_disciplina_id' => 'Só faz exame de recurso quem tem a disciplina abaixo da nota mínima.']);
            }

            // As regras da escola: máximo de negativas e disciplinas-chave decidem se o aluno tem direito ao recurso.
            $regra = $this->regras->paraTurma($turma);
            $direito = $regra === null ? null : ($this->elegibilidade->paraMatricula($turma, $regra, $inscricao->matricula_id));
            if ($direito !== null && ! $direito['elegivel']) {
                throw ValidationException::withMessages(['inscricao_disciplina_id' => $direito['texto'] ?? 'Este aluno não tem direito a exame de recurso.']);
            }

            // O recurso só abre com a 1.ª fase aprovada, e só para quem ela mandou ao recurso.
            $this->pautas->exigirRecursoAberto($turma->id);
            if (! in_array($inscricao->matricula_id, $this->pautas->matriculasEmRecurso($turma->id), true)) {
                throw ValidationException::withMessages(['inscricao_disciplina_id' => 'Este aluno não foi mandado ao recurso pela pauta.']);
            }

            $recurso = AvaliacaoInstrumento::query()->where('turma_id', $turma->id)->where('disciplina_id', $dto->disciplina_id)->where('tipo', TipoInstrumento::RECURSO->value)->first()
                ?? $this->criar->executar(new InstrumentoDTO($turma->id, $dto->disciplina_id, $this->terceiroTrimestre($turma)->id, TipoInstrumento::RECURSO, 'Exame de recurso'));

            return $this->lancar->executar(new LancarNotaDTO($recurso->id, $dto->inscricao_disciplina_id, $dto->valor, $dto->motivo));
        }));
    }

    private function terceiroTrimestre(Turma $turma): Periodo
    {
        return Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->where('numero', 3)->first()
            ?? throw ValidationException::withMessages(['turma_id' => 'O ano lectivo desta turma não tem o 3.º trimestre.']);
    }
}
