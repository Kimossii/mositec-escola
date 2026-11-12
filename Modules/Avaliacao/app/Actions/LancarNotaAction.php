<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\LancarNotaDTO;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Services\AcessoAMiniPautaService;
use Modules\Avaliacao\Services\AutorizaLancamentoService;
use Modules\Avaliacao\Services\DocenteDoUtilizadorService;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Services\PrazoService;
use Modules\Avaliacao\Services\RegraAvaliacaoService;
use Modules\Core\Traits\LogService;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/**
 * Lança ou altera a nota de um aluno num instrumento. Dentro do prazo: docente atribuído à turma e disciplina com
 * `nota.criar` (nova) ou `nota.editar` (existente). Depois do prazo: só quem tem `nota.lancar-fora-prazo`, com motivo.
 * Quem tem `lancar-fora-prazo` também lança dentro do prazo, sem ser docente atribuído. Cada alteração grava histórico.
 */
class LancarNotaAction
{
    use LogService;

    public function __construct(
        private PrazoService $prazos,
        private RegraAvaliacaoService $regras,
        private DocenteDoUtilizadorService $docentes,
        private AutorizaLancamentoService $autorizacao,
        private PautaFinalService $pautas,
        private AcessoAMiniPautaService $acesso,
    ) {
    }

    public function executar(LancarNotaDTO $dto): AvaliacaoNota
    {
        return $this->comLog('Lançar nota', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(fn () => $this->gravar($dto)));
    }

    private function gravar(LancarNotaDTO $dto): AvaliacaoNota
    {
        $instrumento = AvaliacaoInstrumento::query()->findOrFail($dto->instrumento_id);
        $this->acesso->exigirDoUtilizadorActual($instrumento->turma_id, $instrumento->disciplina_id);
        $this->pautas->exigirAbertaPara($instrumento->turma_id, $instrumento->tipo);
        $turma = Turma::query()->findOrFail($instrumento->turma_id);
        $regra = $this->regras->paraTurma($turma)
            ?? throw ValidationException::withMessages(['instrumento_id' => 'A escola ainda não configurou a regra de avaliação desta classe.']);

        $this->validarInscricao($instrumento, $dto->inscricao_disciplina_id);
        $this->validarValor($dto->valor, $regra->escala_maxima);

        $nota = AvaliacaoNota::query()
            ->where('instrumento_id', $instrumento->id)
            ->where('inscricao_disciplina_id', $dto->inscricao_disciplina_id)
            ->lockForUpdate()
            ->first();

        $utilizador = auth()->user();
        $foraDoPrazo = $this->prazos->instrumentoEncerrado($instrumento, $this->docentes->idDe($utilizador));
        $this->autorizacao->exigir($instrumento->turma_id, $instrumento->disciplina_id, $foraDoPrazo, $dto->motivo, [$nota !== null ? 'nota.editar' : 'nota.criar']);

        $anterior = $nota?->only(['valor', 'estado']);

        if ($nota !== null && $nota->estado === EstadoNota::LANCADA && bccomp((string) $nota->valor, $dto->valor, 2) === 0) {
            return $nota;
        }

        $nota ??= new AvaliacaoNota(['instrumento_id' => $instrumento->id, 'inscricao_disciplina_id' => $dto->inscricao_disciplina_id]);
        $nota->fill(['valor' => $dto->valor, 'estado' => EstadoNota::LANCADA])->save();

        $nota->historico()->create([
            'valor_anterior' => $anterior['valor'] ?? null,
            'valor_novo' => $dto->valor,
            'estado_anterior' => $anterior['estado'] ?? null,
            'estado_novo' => EstadoNota::LANCADA,
            'fora_do_prazo' => $foraDoPrazo,
            'motivo' => $dto->motivo,
            'criado_por' => $utilizador?->id,
        ]);

        return $nota;
    }

    private function validarInscricao(AvaliacaoInstrumento $instrumento, int $inscricaoId): void
    {
        $inscricao = InscricaoDisciplina::query()->with(['matricula', 'planoCurricularDisciplina'])->findOrFail($inscricaoId);

        if ($inscricao->matricula->turma_id !== $instrumento->turma_id
            || $inscricao->planoCurricularDisciplina->disciplina_id !== $instrumento->disciplina_id) {
            throw ValidationException::withMessages(['inscricao_disciplina_id' => 'O aluno não está inscrito nesta disciplina e turma.']);
        }
    }

    private function validarValor(string $valor, int $escalaMaxima): void
    {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $valor) || bccomp($valor, (string) $escalaMaxima, 2) > 0) {
            throw ValidationException::withMessages(['valor' => "A nota tem de estar entre 0 e {$escalaMaxima}."]);
        }
    }
}
