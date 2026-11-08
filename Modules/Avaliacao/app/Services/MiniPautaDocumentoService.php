<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Documentos\DocumentoPauta;
use Modules\Avaliacao\Models\AvaliacaoCompetencia;
use Modules\Avaliacao\Models\AvaliacaoCompetenciaRegisto;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa;
use Modules\Disciplina\Models\Disciplina;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/**
 * A mini-pauta de uma disciplina no formato dos documentos oficiais: por trimestre MAC, a prova e a MT, e no fim a MFD
 * (com NE e MF nas classes com exame). Nas 7.ª a 9.ª a prova chama-se NPT.
 */
class MiniPautaDocumentoService
{
    public function __construct(
        private MiniPautaService $miniPauta,
        private ResultadoDisciplinaService $resultados,
        private RegraAvaliacaoService $regras,
        private CabecalhoDocumentoService $cabecalho,
        private CalculadoraQuantitativa $calculadora,
    ) {
    }

    /** Sigla da prova trimestral: NPT na 7.ª a 9.ª classes, PT nas restantes. */
    public static function rotuloDaProva(?string $codigoNivel): string
    {
        return in_array($codigoNivel, ['SEC_7', 'SEC_8', 'SEC_9'], true) ? 'NPT' : 'PT';
    }

    public function paraTurmaDisciplina(int $turmaId, int $disciplinaId): DocumentoPauta
    {
        $turma = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome', 'nivelAcademico:id,nome,codigo'])->findOrFail($turmaId);
        $disciplina = Disciplina::query()->findOrFail($disciplinaId);

        $qualitativa = $this->regraQualitativa($turma);
        if ($qualitativa !== null) {
            return $this->qualitativa($turma, $disciplina, $qualitativa);
        }

        $regra = $this->regras->paraTurma($turma)
            ?? throw ValidationException::withMessages(['turma' => 'A escola ainda não configurou a regra de avaliação desta classe.']);

        $inscricoes = $this->miniPauta->inscricoes($turmaId, $disciplinaId)
            ->sortBy(fn (InscricaoDisciplina $i) => mb_strtolower((string) $i->matricula->aluno?->dadosPessoa?->nome_completo))->values();
        $resultados = $this->resultados->calcularTurma($turmaId, $disciplinaId, $inscricoes->pluck('id')->all());
        $comExame = $regra->com_exame;
        $comRecurso = $regra->com_recurso;
        $prova = self::rotuloDaProva($turma->nivelAcademico?->codigo);
        $cabecalho = $this->cabecalho->dados();

        $mostrar = fn (?string $v) => $v === null ? '' : $this->calculadora->arredondar($v, $regra->casas_decimais, $regra->modo_arredondamento);

        $linhas = [];
        foreach ($inscricoes as $i => $inscricao) {
            $r = $resultados[$inscricao->id];
            $linha = [(string) ($i + 1), (string) $inscricao->matricula->aluno?->dadosPessoa?->nome_completo];
            foreach ([1, 2, 3] as $n) {
                array_push($linha, $mostrar($r->mac[$n]), $mostrar($r->pt[$n]), $mostrar($r->mt[$n]));
            }
            $linha[] = (string) ($r->mfdArredondada ?? '');
            if ($comExame) {
                array_push($linha, $mostrar($r->ne), (string) ($r->mfArredondada ?? ''));
            }
            if ($comRecurso) {
                array_push($linha, $mostrar($r->ner), (string) ($r->mfrArredondada ?? ''));
            }
            $linha[] = '';
            $linhas[] = $linha;
        }

        $ano = (string) $turma->anoLectivo?->nome;
        $grupos = array_map(fn (string $t, int $n) => ['rotulo' => $t, 'colunas' => ['MAC', $prova, 'MT'.$n]], ['I TRIMESTRE', 'II TRIMESTRE', 'III TRIMESTRE'], [1, 2, 3]);
        $grupos[] = ['rotulo' => 'FINAL', 'colunas' => [...($comExame ? ['MFD', 'NE', 'MF'] : ['MFD']), ...($comRecurso ? ['NER', 'MFR'] : [])]];

        return new DocumentoPauta(
            titulo: 'MINI-PAUTA',
            linhasCabecalho: $cabecalho['linhas'],
            lema: $cabecalho['lema'],
            logotipo: $cabecalho['logotipo'],
            meta: [['DISCIPLINA', (string) $disciplina->nome], ['CLASSE', (string) $turma->nivelAcademico?->nome], ['TURMA', (string) $turma->nome], ['ANO LECTIVO', $ano]],
            fixas: ['N.º', 'Nome'],
            grupos: $grupos,
            finais: ['Obs.'],
            linhas: $linhas,
            assinaturas: [
                ['rotulo' => 'O Professor', 'nome' => null, 'cargo' => null],
                ['rotulo' => $cabecalho['responsavel']['cargo'] ?: 'A Direcção', 'nome' => $cabecalho['responsavel']['nome'], 'cargo' => null],
            ],
        );
    }

    private function regraQualitativa(Turma $turma): ?AvaliacaoRegraQualitativa
    {
        return $this->regras->qualitativaParaTurma($turma);
    }

    /**
     * A mini-pauta qualitativa de uma área: o nível de cada trimestre, a MFD (o nível do último trimestre) e as observações do
     * educador, cada uma identificada pelo trimestre.
     */
    private function qualitativa(Turma $turma, Disciplina $disciplina, AvaliacaoRegraQualitativa $regra): DocumentoPauta
    {
        $inscricoes = $this->ordenadas($this->miniPauta->inscricoes($turma->id, $disciplina->id));
        $periodos = Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->get()->keyBy('numero');
        $avaliacoes = AvaliacaoQualitativa::query()->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->with('nivel')->get()->groupBy('inscricao_disciplina_id');
        $cabecalho = $this->cabecalho->dados();

        $linhas = [];
        foreach ($inscricoes as $i => $inscricao) {
            $doAluno = $avaliacoes[$inscricao->id] ?? collect();
            $linha = [(string) ($i + 1), (string) $inscricao->matricula->aluno?->dadosPessoa?->nome_completo];
            $observacoes = [];
            foreach ([1 => 'I', 2 => 'II', 3 => 'III'] as $n => $romano) {
                $a = isset($periodos[$n]) ? $doAluno->firstWhere('periodo_id', $periodos[$n]->id) : null;
                $linha[] = (string) ($a?->nivel->designacao ?? '');
                if ($a?->observacao) {
                    $observacoes[] = "{$romano} T: {$a->observacao}";
                }
            }
            $linha[] = $linha[4];
            $linha[] = implode(' · ', $observacoes);
            $linhas[] = $linha;
        }

        return new DocumentoPauta(
            titulo: 'MINI-PAUTA',
            linhasCabecalho: $cabecalho['linhas'],
            lema: $cabecalho['lema'],
            logotipo: $cabecalho['logotipo'],
            meta: [['ÁREA', (string) $disciplina->nome], ['CLASSE', (string) $turma->nivelAcademico?->nome], ['TURMA', (string) $turma->nome], ['ANO LECTIVO', (string) $turma->anoLectivo?->nome], ['ESCALA', (string) $regra->escala->nome]],
            fixas: ['N.º', 'Nome da criança'],
            grupos: [
                ['rotulo' => 'I TRIMESTRE', 'colunas' => ['Avaliação']], ['rotulo' => 'II TRIMESTRE', 'colunas' => ['Avaliação']],
                ['rotulo' => 'III TRIMESTRE', 'colunas' => ['Avaliação']], ['rotulo' => 'FINAL', 'colunas' => ['MFD']],
            ],
            finais: ['Observações do educador'],
            linhas: $linhas,
            assinaturas: $this->assinaturas('O Educador(a)', $cabecalho),
        );
    }

    /**
     * O detalhe por competência de uma área num trimestre: uma coluna por competência (C1, C2…, com a legenda no fim) e,
     * no fim, o nível que o educador deu à área. Sem `$periodoId`, o primeiro trimestre com registos.
     */
    public function competenciasParaTurmaDisciplina(int $turmaId, int $disciplinaId, ?int $periodoId = null): DocumentoPauta
    {
        $turma = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome', 'nivelAcademico:id,nome,codigo'])->findOrFail($turmaId);
        $disciplina = Disciplina::query()->findOrFail($disciplinaId);
        $regra = $this->regraQualitativa($turma) ?? throw ValidationException::withMessages(['turma' => 'O detalhe por competência só existe na Creche e no Pré-Escolar.']);

        $competencias = AvaliacaoCompetencia::query()->where('disciplina_id', $disciplinaId)->where('ativa', true)
            ->where(fn ($q) => $q->whereNull('nivel_academico_id')->orWhere('nivel_academico_id', $turma->nivel_academico_id))->orderBy('ordem')->orderBy('id')->get()->values();
        if ($competencias->isEmpty()) {
            throw ValidationException::withMessages(['turma' => 'Esta área ainda não tem competências definidas.']);
        }

        $inscricoes = $this->ordenadas($this->miniPauta->inscricoes($turmaId, $disciplinaId));
        $periodos = Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->orderBy('numero')->get();
        $registos = AvaliacaoCompetenciaRegisto::query()->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->whereIn('competencia_id', $competencias->pluck('id'))->with('nivel')->get();

        $periodo = $periodos->firstWhere('id', $periodoId) ?? $periodos->first(fn (Periodo $p) => $registos->contains('periodo_id', $p->id)) ?? $periodos->first()
            ?? throw ValidationException::withMessages(['turma' => 'O ano lectivo não tem trimestres.']);

        $doPeriodo = $registos->where('periodo_id', $periodo->id)->groupBy('inscricao_disciplina_id');
        $areas = AvaliacaoQualitativa::query()->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->where('periodo_id', $periodo->id)->with('nivel')->get()->keyBy('inscricao_disciplina_id');
        $cabecalho = $this->cabecalho->dados();

        $linhas = [];
        foreach ($inscricoes as $i => $inscricao) {
            $linha = [(string) ($i + 1), (string) $inscricao->matricula->aluno?->dadosPessoa?->nome_completo];
            foreach ($competencias as $competencia) {
                $linha[] = (string) (($doPeriodo[$inscricao->id] ?? collect())->firstWhere('competencia_id', $competencia->id)?->nivel->designacao ?? '');
            }
            $linha[] = (string) ($areas[$inscricao->id]->nivel->designacao ?? '');
            $linhas[] = $linha;
        }

        return new DocumentoPauta(
            titulo: 'COMPETÊNCIAS POR ÁREA',
            linhasCabecalho: $cabecalho['linhas'],
            lema: $cabecalho['lema'],
            logotipo: $cabecalho['logotipo'],
            meta: [['ÁREA', (string) $disciplina->nome], ['CLASSE', (string) $turma->nivelAcademico?->nome], ['TURMA', (string) $turma->nome], ['ANO LECTIVO', (string) $turma->anoLectivo?->nome], ['PERÍODO', (string) $periodo->nome]],
            fixas: ['N.º', 'Nome da criança'],
            grupos: [['rotulo' => 'COMPETÊNCIAS', 'colunas' => $competencias->map(fn ($c, int $k) => 'C'.($k + 1))->all()]],
            finais: ['Nível da área'],
            linhas: $linhas,
            assinaturas: $this->assinaturas('O Educador(a)', $cabecalho),
            nota: $competencias->map(fn ($c, int $k) => 'C'.($k + 1).' — '.$c->descricao)->implode(' · '),
        );
    }

    /** @return \Illuminate\Support\Collection<int, InscricaoDisciplina> por ordem alfabética do nome */
    private function ordenadas(Collection $inscricoes): Collection
    {
        return $inscricoes->sortBy(fn (InscricaoDisciplina $i) => mb_strtolower((string) $i->matricula->aluno?->dadosPessoa?->nome_completo))->values();
    }

    /** @return list<array{rotulo: string, nome: ?string, cargo: ?string}> */
    private function assinaturas(string $quem, array $cabecalho): array
    {
        return [
            ['rotulo' => $quem, 'nome' => null, 'cargo' => null],
            ['rotulo' => $cabecalho['responsavel']['cargo'] ?: 'A Direcção', 'nome' => $cabecalho['responsavel']['nome'], 'cargo' => null],
        ];
    }
}
