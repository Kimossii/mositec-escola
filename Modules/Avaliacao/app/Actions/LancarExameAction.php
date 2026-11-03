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
use Modules\Core\Traits\LogService;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/**
 * Lança a nota do exame próprio (NE) de um aluno. O exame de cada turma e disciplina cria-se na primeira nota, no 3.º
 * trimestre, e a nota segue as regras de quem pode e de prazo das restantes notas. Se a nota for recusada, o exame criado
 * desfaz-se com ela (mesma transacção).
 */
class LancarExameAction
{
    use LogService;

    public function __construct(private CriarInstrumentoAction $criar, private LancarNotaAction $lancar)
    {
    }

    public function executar(int $turmaId, int $disciplinaId, int $inscricaoDisciplinaId, string $valor, ?string $motivo = null): AvaliacaoNota
    {
        return $this->comLog('Lançar nota de exame', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($turmaId, $disciplinaId, $inscricaoDisciplinaId, $valor, $motivo) {
            $turma = Turma::query()->findOrFail($turmaId);
            $inscricao = InscricaoDisciplina::query()->with(['matricula', 'planoCurricularDisciplina'])->findOrFail($inscricaoDisciplinaId);

            if ($inscricao->matricula->turma_id !== $turma->id || $inscricao->planoCurricularDisciplina->disciplina_id !== $disciplinaId) {
                throw ValidationException::withMessages(['inscricao_disciplina_id' => 'O aluno não está inscrito nesta disciplina e turma.']);
            }

            $exame = AvaliacaoInstrumento::query()->where('turma_id', $turma->id)->where('disciplina_id', $disciplinaId)->where('tipo', TipoInstrumento::EXAME->value)->first()
                ?? $this->criar->executar(new InstrumentoDTO($turma->id, $disciplinaId, $this->terceiroTrimestre($turma)->id, TipoInstrumento::EXAME, 'Exame'));

            return $this->lancar->executar(new LancarNotaDTO($exame->id, $inscricaoDisciplinaId, $valor, $motivo));
        }));
    }

    private function terceiroTrimestre(Turma $turma): Periodo
    {
        return Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->where('numero', 3)->first()
            ?? throw ValidationException::withMessages(['turma_id' => 'O ano lectivo desta turma não tem o 3.º trimestre.']);
    }
}
