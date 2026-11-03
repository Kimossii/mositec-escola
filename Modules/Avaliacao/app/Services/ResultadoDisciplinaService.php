<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\DTO\ResultadoDisciplina;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/**
 * Lê notas, instrumentos e prazos e entrega o resultado quantitativo calculado pelo motor. Não escreve na BD nem tem
 * fórmulas próprias. Regras: nota lançada entra; anulada fica fora, salvo "conta como zero"; obrigatória sem nota é
 * pendente até ao prazo e zero depois dele; facultativa sem nota fica fora; PT sem nota idem (pendente, depois zero);
 * PT anulada deixa a MT pendente (a anulação não a transforma em zero).
 */
class ResultadoDisciplinaService
{
    private const TRIMESTRES = [1, 2, 3];

    public function __construct(
        private CalculadoraQuantitativa $calculadora,
        private RegraAvaliacaoService $regras,
        private PrazoService $prazos,
        private DocenteDoUtilizadorService $docentes,
        private RegraPadraoService $padrao,
    ) {
    }

    public function calcular(int $inscricaoDisciplinaId): ResultadoDisciplina
    {
        $inscricao = InscricaoDisciplina::query()->with(['matricula', 'planoCurricularDisciplina'])->findOrFail($inscricaoDisciplinaId);

        return $this->calcularTurma(
            $inscricao->matricula->turma_id,
            $inscricao->planoCurricularDisciplina->disciplina_id,
            [$inscricao->id],
        )[$inscricao->id];
    }

    /**
     * Resultados de todos os alunos inscritos na disciplina da turma (ou só dos `$inscricaoIds`), carregando os dados uma vez.
     *
     * @param  array<int, int>|null  $inscricaoIds
     * @return array<int, ResultadoDisciplina> indexado pelo id da inscrição
     */
    public function calcularTurma(int $turmaId, int $disciplinaId, ?array $inscricaoIds = null): array
    {
        $turma = Turma::query()->with('nivelAcademico:id,codigo')->findOrFail($turmaId);
        $comExame = $this->padrao->temExameProprio((string) $turma->nivelAcademico?->codigo);
        $regra = $this->regras->paraTurma($turma)
            ?? throw ValidationException::withMessages(['turma_id' => 'A escola ainda não configurou a regra de avaliação desta classe.']);

        $inscricoes = InscricaoDisciplina::query()
            ->whereHas('matricula', fn ($q) => $q->where('turma_id', $turmaId))
            ->whereHas('planoCurricularDisciplina', fn ($q) => $q->where('disciplina_id', $disciplinaId))
            ->when($inscricaoIds !== null, fn ($q) => $q->whereIn('id', $inscricaoIds))
            ->pluck('id');

        $periodos = Periodo::query()
            ->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)
            ->get()->keyBy('numero');

        $instrumentos = AvaliacaoInstrumento::query()
            ->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->get();

        $notas = AvaliacaoNota::query()
            ->whereIn('instrumento_id', $instrumentos->pluck('id'))->whereIn('inscricao_disciplina_id', $inscricoes)
            ->get()->groupBy('inscricao_disciplina_id');

        $docenteId = $this->docentes->titularDe($turmaId, $disciplinaId);
        $encerrado = $this->prazosEncerrados($regra, $periodos, $turmaId, $disciplinaId, $docenteId);

        $resultados = [];
        foreach ($inscricoes as $inscricaoId) {
            $doAluno = ($notas[$inscricaoId] ?? collect())->keyBy('instrumento_id');
            $resultados[$inscricaoId] = $this->resultado($regra, $periodos, $instrumentos, $doAluno, $encerrado, $comExame);
        }

        return $resultados;
    }

    /** @return array<int, array<string, bool>> [trimestre][tipo->name] */
    private function prazosEncerrados(AvaliacaoRegra $regra, Collection $periodos, int $turmaId, int $disciplinaId, ?int $docenteId): array
    {
        $encerrado = [];

        foreach (self::TRIMESTRES as $n) {
            foreach (TipoInstrumento::cases() as $tipo) {
                $encerrado[$n][$tipo->name] = isset($periodos[$n])
                    && $this->prazos->prazoEncerrado($regra, $periodos[$n]->id, $tipo, $turmaId, $disciplinaId, $docenteId);
            }
        }

        return $encerrado;
    }

    private function resultado(AvaliacaoRegra $regra, Collection $periodos, Collection $instrumentos, Collection $notas, array $encerrado, bool $comExame): ResultadoDisciplina
    {
        $mac = $pt = $mt = $pendentes = [];

        foreach (self::TRIMESTRES as $n) {
            $doTrimestre = isset($periodos[$n]) ? $instrumentos->where('periodo_id', $periodos[$n]->id) : collect();
            $pendente = false;

            $valores = [];
            foreach ($doTrimestre->where('tipo', TipoInstrumento::MAC) as $instrumento) {
                $valor = $this->valorConsiderado($notas[$instrumento->id] ?? null, $instrumento->obrigatorio, $encerrado[$n][TipoInstrumento::MAC->name], $pendente);
                if ($valor !== null) {
                    $valores[] = $valor;
                }
            }

            $mac[$n] = $this->calculadora->media($valores);

            $prova = $doTrimestre->firstWhere('tipo', TipoInstrumento::PT);
            $pt[$n] = null;
            if ($prova !== null) {
                $nota = $notas[$prova->id] ?? null;
                if ($nota?->estado === EstadoNota::ANULADA) {
                    $pendente = true;
                } else {
                    $pt[$n] = $this->valorConsiderado($nota, true, $encerrado[$n][TipoInstrumento::PT->name], $pendente);
                }
            } else {
                $pendente = true;
            }

            $pendente = $pendente || $mac[$n] === null;
            $pendentes[$n] = $pendente;
            $mt[$n] = $pendente ? null : $this->calculadora->mt($mac[$n], $pt[$n], (string) $regra->peso_mac, (string) $regra->peso_pt);
        }

        $mfd = $this->calculadora->mfd([$mt[1], $mt[2], $mt[3]], $regra->pesosDosTrimestres());
        $arredondada = $mfd === null ? null : $this->calculadora->arredondar($mfd, $regra->casas_decimais, $regra->modo_arredondamento);

        [$ne, $mf, $mfArredondada] = $comExame ? $this->exame($regra, $instrumentos, $notas, $mfd) : [null, null, null];

        return new ResultadoDisciplina($mac, $pt, $mt, $pendentes, $mfd, $arredondada, $ne, $mf, $mfArredondada, $comExame);
    }

    /**
     * Exame próprio: NE lançada (ou anulada a contar como zero) com a MFD dá a MF. Sem exame criado, sem nota ou com a
     * nota anulada o resultado fica pendente: um exame em falta não se transforma em zero por si.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [NE, MF exacta, MF arredondada]
     */
    private function exame(AvaliacaoRegra $regra, Collection $instrumentos, Collection $notas, ?string $mfd): array
    {
        $exame = $instrumentos->firstWhere('tipo', TipoInstrumento::EXAME);
        $nota = $exame === null ? null : ($notas[$exame->id] ?? null);

        $ne = match ($nota?->estado) {
            EstadoNota::LANCADA => (string) $nota->valor,
            EstadoNota::ANULADA_CONTA_ZERO => '0',
            default => null,
        };

        if ($ne === null || $mfd === null) {
            return [$ne, null, null];
        }

        $mf = $this->calculadora->mfComExame($mfd, $ne, (string) $regra->peso_mfd_exame, (string) $regra->peso_exame);

        return [$ne, $mf, $this->calculadora->arredondar($mf, $regra->casas_decimais, $regra->modo_arredondamento)];
    }

    /** Valor que a nota contribui para o cálculo, ou `null` se fica de fora; marca `$pendente` quando ainda falta lançar. */
    private function valorConsiderado(?AvaliacaoNota $nota, bool $obrigatorio, bool $prazoEncerrado, bool &$pendente): ?string
    {
        if ($nota === null) {
            if (! $obrigatorio) {
                return null;
            }
            if ($prazoEncerrado) {
                return '0';
            }
            $pendente = true;

            return null;
        }

        return match ($nota->estado) {
            EstadoNota::LANCADA => (string) $nota->valor,
            EstadoNota::ANULADA_CONTA_ZERO => '0',
            EstadoNota::ANULADA => null,
        };
    }
}
