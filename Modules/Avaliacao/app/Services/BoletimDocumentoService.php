<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Enums\FasePauta;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Documentos\DocumentoBoletim;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

/**
 * Os boletins de uma turma, ou de um só aluno, com as notas e a nomenclatura da pauta (MT1, MT2, MT3, MFD, NE, MF, NER,
 * MFR; os níveis qualitativos da escala). A decisão (Transita, Apto…) só aparece com a pauta definitiva; antes disso o
 * boletim diz que o resultado é provisório. Emitir um boletim nunca é aprovar ou fazer transitar.
 */
class BoletimDocumentoService
{
    public function __construct(
        private PautaFinalService $pautas,
        private PautaDocumentoService $colunasDaPauta,
        private CabecalhoDocumentoService $cabecalho,
    ) {
    }

    /** @return list<DocumentoBoletim> um por aluno, por ordem alfabética (ou só o da `$matriculaId`) */
    public function paraTurma(int $turmaId, ?int $matriculaId = null): array
    {
        $turma = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome', 'nivelAcademico:id,nome'])->findOrFail($turmaId);
        $pauta = $this->pautas->paraTurma($turmaId);
        $cabecalho = $this->cabecalho->dados();

        // Quem foi ao recurso tem o resultado final na pauta do recurso (quando já está aprovada); os outros, na da 1.ª fase.
        $recurso = $this->pautas->definitiva($turmaId, FasePauta::RECURSO) ? $this->pautas->paraTurma($turmaId, FasePauta::RECURSO) : null;
        $emRecurso = $recurso === null ? [] : array_column($recurso['alunos'], 'matricula_id');

        $fontes = [];
        foreach ($pauta['alunos'] as $a) {
            if (! in_array($a['matricula_id'], $emRecurso, true)) {
                $fontes[] = [$pauta, $a];
            }
        }
        foreach ($recurso['alunos'] ?? [] as $a) {
            $fontes[] = [$recurso, $a];
        }
        $fontes = array_values(array_filter($fontes, fn (array $f) => $matriculaId === null || $f[1]['matricula_id'] === $matriculaId));
        if ($matriculaId !== null && $fontes === []) {
            throw ValidationException::withMessages(['matricula' => 'Este aluno não faz parte da pauta desta turma.']);
        }
        usort($fontes, fn (array $x, array $y) => strnatcasecmp($x[1]['nome'], $y[1]['nome']));

        $qualitativa = ($pauta['tipo'] ?? 'quantitativa') === 'qualitativa';
        $alunos = array_column($fontes, 1);
        $observacoes = $qualitativa ? $this->observacoes($alunos, $pauta['disciplinas']) : [];
        $matriculas = Matricula::query()->whereIn('id', array_column($alunos, 'matricula_id'))->with('aluno')->get()->keyBy('id');

        return array_map(function (array $fonte) use ($turma, $cabecalho, $qualitativa, $observacoes, $matriculas) {
            [$pauta, $aluno] = $fonte;
            $definitivo = ($pauta['estado'] ?? 'provisoria') === 'definitiva';
            [$campos, $rotulos] = $this->colunasDaPauta->colunas($pauta);
            $colunas = [$qualitativa ? 'Área' : 'Disciplina', ...$rotulos];
            $linhas = array_map(fn (array $d) => [$d['nome'], ...array_map(fn (string $campo) => (string) ($aluno['celulas'][$d['id']][$campo] ?? ''), $campos)], $pauta['disciplinas']);

            return new DocumentoBoletim(
                titulo: 'BOLETIM DE AVALIAÇÃO'.($qualitativa ? ' — EDUCAÇÃO PRÉ-ESCOLAR' : ''),
                linhasCabecalho: $cabecalho['linhas'],
                lema: $cabecalho['lema'],
                logotipo: $cabecalho['logotipo'],
                identificacao: array_values(array_filter([
                    ['Nome', $aluno['nome']],
                    $matriculas[$aluno['matricula_id']]->aluno?->numero_matricula ? ['N.º de matrícula', (string) $matriculas[$aluno['matricula_id']]->aluno->numero_matricula] : null,
                    ['Classe', (string) $turma->nivelAcademico?->nome],
                    ['Turma', (string) $turma->nome],
                    $turma->turno?->nome ? ['Período', (string) $turma->turno->nome] : null,
                    ['Ano lectivo', (string) $turma->anoLectivo?->nome],
                ])),
                colunas: $colunas,
                linhas: $linhas,
                resultado: $definitivo && $aluno['decisao'] !== null ? mb_strtoupper($aluno['decisao']['rotulo']) : 'RESULTADO PROVISÓRIO',
                resultadoDefinitivo: $definitivo && $aluno['decisao'] !== null,
                observacoes: $observacoes[$aluno['matricula_id']] ?? [],
                assinaturas: [
                    ['rotulo' => $qualitativa ? 'O Educador(a)' : 'O Professor', 'nome' => null],
                    ['rotulo' => 'O Encarregado de Educação', 'nome' => null],
                    ['rotulo' => $cabecalho['responsavel']['cargo'] ?: 'A Direcção', 'nome' => $cabecalho['responsavel']['nome']],
                ],
                nota: 'A emissão deste boletim não representa aprovação nem transição de classe.'.($definitivo ? '' : ' Os resultados são provisórios até a pauta ser aprovada.'),
            );
        }, $fontes);
    }

    /**
     * As observações do educador de cada criança, por área e trimestre («Linguagem — I T: participa bem»).
     *
     * @param  list<array<string, mixed>>  $alunos
     * @param  list<array{id: int, nome: string}>  $disciplinas
     * @return array<int, list<string>> matrícula => frases
     */
    private function observacoes(array $alunos, array $disciplinas): array
    {
        $inscricoes = [];
        foreach ($alunos as $a) {
            foreach ($a['celulas'] as $disciplinaId => $celula) {
                $inscricoes[$celula['inscricao_id']] = [$a['matricula_id'], $disciplinaId];
            }
        }

        $nomes = array_column($disciplinas, 'nome', 'id');
        $resultado = [];
        $avaliacoes = AvaliacaoQualitativa::query()->whereIn('inscricao_disciplina_id', array_keys($inscricoes))->whereNotNull('observacao')->with('periodo:id,numero')->get()
            ->sortBy(fn (AvaliacaoQualitativa $q) => [$inscricoes[$q->inscricao_disciplina_id][1], $q->periodo->numero]);

        foreach ($avaliacoes as $q) {
            [$matriculaId, $disciplinaId] = $inscricoes[$q->inscricao_disciplina_id];
            $resultado[$matriculaId][] = ($nomes[$disciplinaId] ?? '').' — '.[1 => 'I', 2 => 'II', 3 => 'III'][$q->periodo->numero].' T: '.$q->observacao;
        }

        return $resultado;
    }
}
