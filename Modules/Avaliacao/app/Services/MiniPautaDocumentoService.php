<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Documentos\DocumentoPauta;
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
        private RegraPadraoService $padrao,
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

        if (AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('nivel_academico_id', $turma->nivel_academico_id)->exists()) {
            throw ValidationException::withMessages(['turma' => 'A exportação da mini-pauta qualitativa ainda não está disponível.']);
        }

        $regra = $this->regras->paraTurma($turma)
            ?? throw ValidationException::withMessages(['turma' => 'A escola ainda não configurou a regra de avaliação desta classe.']);

        $inscricoes = $this->miniPauta->inscricoes($turmaId, $disciplinaId)
            ->sortBy(fn (InscricaoDisciplina $i) => mb_strtolower((string) $i->matricula->aluno?->dadosPessoa?->nome_completo))->values();
        $resultados = $this->resultados->calcularTurma($turmaId, $disciplinaId, $inscricoes->pluck('id')->all());
        $comExame = $this->padrao->temExameProprio((string) $turma->nivelAcademico?->codigo);
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
            $linha[] = '';
            $linhas[] = $linha;
        }

        $ano = (string) $turma->anoLectivo?->nome;
        $grupos = array_map(fn (string $t, int $n) => ['rotulo' => $t, 'colunas' => ['MAC', $prova, 'MT'.$n]], ['I TRIMESTRE', 'II TRIMESTRE', 'III TRIMESTRE'], [1, 2, 3]);
        $grupos[] = ['rotulo' => 'FINAL', 'colunas' => $comExame ? ['MFD', 'NE', 'MF'] : ['MFD']];

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
}
