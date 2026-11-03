<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\DTO\LancarQualitativaDTO;
use Modules\Avaliacao\Models\AvaliacaoEscalaNivel;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/**
 * Lança ou altera o nível qualitativo de uma criança numa área e trimestre. Mesmas regras de quem pode que as notas:
 * dentro do prazo, o docente atribuído; depois do prazo (fim do trimestre), só quem tem `nota.lancar-fora-prazo`, com motivo.
 * O nível tem de pertencer à escala da classe. Cada alteração fica no histórico.
 */
class LancarQualitativaAction
{
    use LogService;

    public function __construct(
        private PautaFinalService $pautas,
        private VerificaAtribuicaoDocente $atribuicoes,
        private RelogioDoTenant $relogio,
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
        $foraDoPrazo = $this->relogio->hoje()->toDateString() > $periodo->data_fim->toDateString();
        $this->autorizar($turma->id, $disciplinaId, $foraDoPrazo, $dto->motivo);

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

    private function autorizar(int $turmaId, int $disciplinaId, bool $foraDoPrazo, ?string $motivo): void
    {
        $utilizador = auth()->user();
        $temExcepcao = Gate::allows('nota.lancar-fora-prazo');

        if ($foraDoPrazo) {
            if (! $temExcepcao) {
                throw new AuthorizationException('O prazo de lançamento terminou.');
            }
            if ($motivo === null || trim($motivo) === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo do lançamento fora do prazo.']);
            }

            return;
        }

        $podeNaTurma = $utilizador !== null
            && (Gate::allows('nota.criar') || Gate::allows('nota.editar'))
            && $this->atribuicoes->podeGerir($utilizador->id, $turmaId, $disciplinaId);

        if (! $podeNaTurma && ! $temExcepcao) {
            throw new AuthorizationException('Não lecciona esta área nesta turma.');
        }
    }
}
