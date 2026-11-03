<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\DTO\LancarQualitativaDTO;
use Modules\Avaliacao\Models\AvaliacaoEscalaNivel;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Services\AutorizaLancamentoService;
use Modules\Avaliacao\Services\DocenteDoUtilizadorService;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Services\PrazoService;
use Modules\Core\Traits\LogService;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/**
 * Lança ou altera o nível qualitativo de uma criança numa área e trimestre. Mesmas regras de quem pode que as notas:
 * dentro do prazo, o docente atribuído; depois do prazo (o da regra, ou o fim do trimestre se não houver), só quem tem `nota.lancar-fora-prazo`, com motivo.
 * O nível tem de pertencer à escala da classe. Cada alteração fica no histórico.
 */
class LancarQualitativaAction
{
    use LogService;

    public function __construct(
        private PautaFinalService $pautas,
        private AutorizaLancamentoService $autorizacao,
        private PrazoService $prazos,
        private DocenteDoUtilizadorService $docentes,
    ) {
    }

    public function executar(LancarQualitativaDTO $dto): AvaliacaoQualitativa
    {
        return $this->comLog('Lançar avaliação qualitativa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(fn () => $this->gravar($dto)));
    }

    private function gravar(LancarQualitativaDTO $dto): AvaliacaoQualitativa
    {
        $inscricao = InscricaoDisciplina::query()->with(['matricula', 'planoCurricularDisciplina'])->findOrFail($dto->inscricao_disciplina_id);
        $turma = Turma::query()->findOrFail($inscricao->matricula->turma_id);
        $this->pautas->exigirAberta($turma->id);

        $regra = AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('nivel_academico_id', $turma->nivel_academico_id)->first()
            ?? throw ValidationException::withMessages(['inscricao_disciplina_id' => 'A escola ainda não escolheu a escala de avaliação desta classe.']);

        $periodo = Periodo::query()->findOrFail($dto->periodo_id);
        if ($periodo->ano_lectivo_id !== $turma->ano_lectivo_id || $periodo->tipo !== TipoPeriodo::TRIMESTRE) {
            throw ValidationException::withMessages(['periodo_id' => 'O trimestre não pertence ao ano lectivo da turma.']);
        }

        $nivel = AvaliacaoEscalaNivel::query()->findOrFail($dto->escala_nivel_id);
        if ($nivel->escala_id !== $regra->escala_id) {
            throw ValidationException::withMessages(['escala_nivel_id' => 'O nível escolhido não pertence à escala desta classe.']);
        }

        $disciplinaId = $inscricao->planoCurricularDisciplina->disciplina_id;
        $foraDoPrazo = $this->prazos->prazoEncerrado($regra, $periodo->id, TipoInstrumento::QUALITATIVA, $turma->id, $disciplinaId, $this->docentes->idDe(auth()->user()));
        $this->autorizacao->exigir($turma->id, $disciplinaId, $foraDoPrazo, $dto->motivo);

        $actual = AvaliacaoQualitativa::query()->where('inscricao_disciplina_id', $inscricao->id)->where('periodo_id', $periodo->id)->lockForUpdate()->first();
        $observacao = $dto->observacao !== null && trim($dto->observacao) !== '' ? trim($dto->observacao) : null;

        if ($actual !== null && $actual->escala_nivel_id === $nivel->id && $actual->observacao === $observacao) {
            return $actual;
        }

        $anterior = $actual?->escala_nivel_id;
        $actual ??= new AvaliacaoQualitativa(['inscricao_disciplina_id' => $inscricao->id, 'periodo_id' => $periodo->id]);
        $actual->fill(['escala_nivel_id' => $nivel->id, 'observacao' => $observacao])->save();

        $actual->historico()->create([
            'escala_nivel_anterior_id' => $anterior, 'escala_nivel_novo_id' => $nivel->id,
            'fora_do_prazo' => $foraDoPrazo, 'motivo' => $dto->motivo, 'criado_por' => auth()->id(),
        ]);

        return $actual;
    }
}
