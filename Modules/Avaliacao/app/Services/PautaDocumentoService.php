<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Documentos\DocumentoPauta;
use Modules\Turma\Models\Turma;

/** A pauta final de uma turma no formato dos documentos oficiais: cabeçalho da escola, colunas por tipo e assinaturas. */
class PautaDocumentoService
{
    public function __construct(private PautaFinalService $pautas, private CabecalhoDocumentoService $cabecalho)
    {
    }

    public function paraTurma(int $turmaId): DocumentoPauta
    {
        $pauta = $this->pautas->paraTurma($turmaId);
        $turma = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome', 'nivelAcademico:id,nome'])->findOrFail($turmaId);
        $cabecalho = $this->cabecalho->dados();

        $qualitativa = ($pauta['tipo'] ?? 'quantitativa') === 'qualitativa';
        $comExame = (bool) ($pauta['com_exame'] ?? false);
        [$campos, $rotulos] = $this->colunas($pauta);

        $linhas = [];
        foreach ($pauta['alunos'] as $i => $aluno) {
            $linha = [(string) ($i + 1), (string) $aluno['nome']];
            if (! $qualitativa) {
                $linha[] = match ((int) ($aluno['sexo'] ?? 0)) { 1 => 'M', 2 => 'F', default => '' };
            }
            foreach ($pauta['disciplinas'] as $d) {
                foreach ($campos as $campo) {
                    $linha[] = (string) ($aluno['celulas'][$d['id']][$campo] ?? '');
                }
            }
            $linha[] = $aluno['decisao'] !== null ? mb_strtoupper($aluno['decisao']['rotulo']) : 'PENDENTE';
            $linhas[] = $linha;
        }

        $estado = $pauta['estado'] ?? 'provisoria';
        $titulo = 'PAUTA FINAL'.(($pauta['fase'] ?? 1) === 2 ? ' — RECURSO' : '').match ($estado) { 'definitiva' => '', 'reaberta' => ' (REABERTA)', default => ' (PROVISÓRIA)' };
        $ano = (string) $turma->anoLectivo?->nome;

        return new DocumentoPauta(
            titulo: $titulo,
            linhasCabecalho: $cabecalho['linhas'],
            lema: $cabecalho['lema'],
            logotipo: $cabecalho['logotipo'],
            meta: array_values(array_filter([
                ['CLASSE', (string) $turma->nivelAcademico?->nome],
                ['TURMA', (string) $turma->nome],
                $turma->turno?->nome ? ['PERÍODO', (string) $turma->turno->nome] : null,
                ['ANO LECTIVO', $ano],
                ['PAUTA N.º', '______/'.$ano],
                ['SALA', '______'],
            ])),
            fixas: $qualitativa ? ['N.º', 'Nome do aluno'] : ['N.º', 'Nome do aluno', 'Sexo'],
            grupos: array_map(fn (array $d) => ['rotulo' => $d['nome'], 'colunas' => $rotulos], $pauta['disciplinas']),
            finais: [$qualitativa ? 'Observação' : 'Resultado final'],
            linhas: $linhas,
            assinaturas: $this->assinaturas($cabecalho),
            nota: $estado === 'definitiva' ? null : 'Resultado sem validade até a pauta ser aprovada.',
        );
    }

    /**
     * As colunas de cada disciplina, na nomenclatura da pauta: MT1, MT2, MT3 e MFD; nas classes com exame, MFD, NE e MF; e
     * nas classes com recurso, também NER e MFR. Partilhado com o boletim, para os dois dizerem o mesmo.
     *
     * @return array{0: list<string>, 1: list<string>} [campos da célula, rótulos]
     */
    public function colunas(array $pauta): array
    {
        $qualitativa = ($pauta['tipo'] ?? 'quantitativa') === 'qualitativa';
        [$campos, $rotulos] = ($pauta['com_exame'] ?? false)
            ? [['mfd', 'ne', 'mf'], ['MFD', 'NE', 'MF']]
            : [['mt1', 'mt2', 'mt3', 'mfd'], ['MT1', 'MT2', 'MT3', 'MFD']];

        if (! $qualitativa && ($pauta['mostra_recurso'] ?? false)) {
            $campos = [...$campos, 'ner', 'mfr'];
            $rotulos = [...$rotulos, 'NER', 'MFR'];
        }

        return [$campos, $rotulos];
    }

    /** @return list<array{rotulo: string, nome: ?string, cargo: ?string}> */
    private function assinaturas(array $cabecalho): array
    {
        $assinaturas = [['rotulo' => $cabecalho['responsavel']['cargo'] ?: 'A Direcção', 'nome' => $cabecalho['responsavel']['nome'], 'cargo' => null]];

        if ($cabecalho['visto'] !== null) {
            $assinaturas[] = ['rotulo' => 'Visto: '.($cabecalho['visto']['cargo'] ?: ''), 'nome' => $cabecalho['visto']['nome'], 'cargo' => null];
        }

        return $assinaturas;
    }
}
