<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\TratarNegativaDTO;
use Modules\Avaliacao\Models\AvaliacaoNegativaTratada;
use Modules\Avaliacao\Services\AcessoAMiniPautaService;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Services\RegraAvaliacaoService;
use Modules\Avaliacao\Services\ResultadoDisciplinaService;
use Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa;
use Modules\Core\Traits\LogService;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/**
 * Regista a medida tomada para a negativa de um aluno numa disciplina. Só nas classes em que o aluno tem de transitar
 * (regra «transita, com as negativas tratadas»), só com `nota.tratar-negativa` e só para quem tem acesso à disciplina.
 * Sem todas as negativas tratadas, a pauta não se aprova.
 */
class TratarNegativaAction
{
    use LogService;

    public function __construct(
        private RegraAvaliacaoService $regras,
        private ResultadoDisciplinaService $resultados,
        private CalculadoraQuantitativa $calculadora,
        private AcessoAMiniPautaService $acesso,
        private PautaFinalService $pautas,
    ) {
    }

    public function executar(TratarNegativaDTO $dto): AvaliacaoNegativaTratada
    {
        return $this->comLog('Tratar negativa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(fn () => $this->gravar($dto)));
    }

    private function gravar(TratarNegativaDTO $dto): AvaliacaoNegativaTratada
    {
        if (! Gate::allows('nota.tratar-negativa')) {
            throw new AuthorizationException('Sem permissão para tratar negativas.');
        }

        $medida = trim($dto->medida);
        if ($medida === '') {
            throw ValidationException::withMessages(['medida' => 'Indique a medida tomada para esta negativa.']);
        }

        $inscricao = InscricaoDisciplina::query()->with(['matricula', 'planoCurricularDisciplina'])->findOrFail($dto->inscricao_disciplina_id);
        $turma = Turma::query()->findOrFail($inscricao->matricula->turma_id);
        $this->acesso->exigirDoUtilizadorActual($turma->id, $inscricao->planoCurricularDisciplina->disciplina_id);
        $this->pautas->exigirAberta($turma->id);

        $regra = $this->regras->paraTurma($turma);
        if ($regra === null || ! $regra->tratamento_negativas->tratamNegativas()) {
            throw ValidationException::withMessages(['inscricao_disciplina_id' => 'Nesta classe a negativa decide a transição: não há tratamento de negativas.']);
        }

        if (! $this->temNegativa($inscricao->id, $regra->casas_decimais, $regra->modo_arredondamento, (string) $regra->nota_minima)) {
            throw ValidationException::withMessages(['inscricao_disciplina_id' => 'Este aluno não tem negativa nesta disciplina.']);
        }

        return AvaliacaoNegativaTratada::updateOrCreate(['inscricao_disciplina_id' => $inscricao->id], ['medida' => $medida]);
    }

    /** Negativa em qualquer trimestre (já lançado) ou na nota final. */
    private function temNegativa(int $inscricaoId, int $casas, $modo, string $minima): bool
    {
        $r = $this->resultados->calcular($inscricaoId);
        $notas = array_map(fn (?string $mt) => $mt === null ? null : $this->calculadora->arredondar($mt, $casas, $modo), array_values($r->mt));
        $notas[] = $r->notaFinalArredondada();

        foreach (array_filter($notas, fn ($n) => $n !== null) as $nota) {
            if (! $this->calculadora->aprovadoNaDisciplina($nota, $minima)) {
                return true;
            }
        }

        return false;
    }
}
