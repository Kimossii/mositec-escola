<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\DTO\LancarCompetenciaDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoCompetencia;
use Modules\Avaliacao\Models\AvaliacaoCompetenciaRegisto;
use Modules\Avaliacao\Models\AvaliacaoEscalaNivel;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Services\AutorizaLancamentoService;
use Modules\Avaliacao\Services\DocenteDoUtilizadorService;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Services\PrazoService;
use Modules\Core\Traits\LogService;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/**
 * Regista o nível de uma criança numa competência da área, num trimestre. Mesmas regras de quem pode e de prazo da avaliação
 * qualitativa da área; a competência tem de ser desta área, estar activa e valer para a classe da turma; o nível, da escala da classe.
 */
class LancarCompetenciaAction
{
    use LogService;

    public function __construct(
        private PautaFinalService $pautas,
        private PrazoService $prazos,
        private DocenteDoUtilizadorService $docentes,
        private AutorizaLancamentoService $autorizacao,
    ) {
    }

    public function executar(LancarCompetenciaDTO $dto): AvaliacaoCompetenciaRegisto
    {
        return $this->comLog('Lançar competência', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(fn () => $this->gravar($dto)));
    }

    private function gravar(LancarCompetenciaDTO $dto): AvaliacaoCompetenciaRegisto
    {
        $inscricao = InscricaoDisciplina::query()->with(['matricula', 'planoCurricularDisciplina'])->findOrFail($dto->inscricao_disciplina_id);
        $turma = Turma::query()->findOrFail($inscricao->matricula->turma_id);
        $disciplinaId = $inscricao->planoCurricularDisciplina->disciplina_id;
        $this->pautas->exigirAberta($turma->id);

        $regra = AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('nivel_academico_id', $turma->nivel_academico_id)->first()
            ?? throw ValidationException::withMessages(['inscricao_disciplina_id' => 'A escola ainda não escolheu a escala de avaliação desta classe.']);

        $periodo = Periodo::query()->findOrFail($dto->periodo_id);
        if ($periodo->ano_lectivo_id !== $turma->ano_lectivo_id || $periodo->tipo !== TipoPeriodo::TRIMESTRE) {
            throw ValidationException::withMessages(['periodo_id' => 'O trimestre não pertence ao ano lectivo da turma.']);
        }

        $competencia = AvaliacaoCompetencia::query()->findOrFail($dto->competencia_id);
        if ($competencia->disciplina_id !== $disciplinaId || ! $competencia->ativa
            || ($competencia->nivel_academico_id !== null && $competencia->nivel_academico_id !== $turma->nivel_academico_id)) {
            throw ValidationException::withMessages(['competencia_id' => 'Esta competência não se aplica a esta área e turma.']);
        }

        $nivel = AvaliacaoEscalaNivel::query()->findOrFail($dto->escala_nivel_id);
        if ($nivel->escala_id !== $regra->escala_id) {
            throw ValidationException::withMessages(['escala_nivel_id' => 'O nível escolhido não pertence à escala desta classe.']);
        }

        $foraDoPrazo = $this->prazos->prazoEncerrado($regra, $periodo->id, TipoInstrumento::QUALITATIVA, $turma->id, $disciplinaId, $this->docentes->idDe(auth()->user()));
        $this->autorizacao->exigir($turma->id, $disciplinaId, $foraDoPrazo, $dto->motivo);

        $registo = AvaliacaoCompetenciaRegisto::query()->where('inscricao_disciplina_id', $inscricao->id)->where('competencia_id', $competencia->id)
            ->where('periodo_id', $periodo->id)->lockForUpdate()->first() ?? new AvaliacaoCompetenciaRegisto([
                'inscricao_disciplina_id' => $inscricao->id, 'competencia_id' => $competencia->id, 'periodo_id' => $periodo->id,
            ]);

        if ($registo->exists && $registo->escala_nivel_id === $nivel->id) {
            return $registo;
        }

        $anterior = $registo->exists ? $registo->escala_nivel_id : null;
        $registo->fill(['escala_nivel_id' => $nivel->id])->save();

        $registo->historico()->create([
            'escala_nivel_anterior_id' => $anterior, 'escala_nivel_novo_id' => $nivel->id,
            'fora_do_prazo' => $foraDoPrazo, 'motivo' => $dto->motivo, 'criado_por' => auth()->id(),
        ]);

        return $registo;
    }
}
