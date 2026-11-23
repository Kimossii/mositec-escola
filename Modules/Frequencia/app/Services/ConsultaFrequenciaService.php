<?php

namespace Modules\Frequencia\Services;

use Carbon\CarbonInterface;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Contracts\ConsultaDocenciaParaHorario;
use Modules\Core\Contracts\ConsultaFaltasDoAluno;
use Modules\Core\Models\Horario;
use Modules\Disciplina\Models\Disciplina;
use Modules\Docente\Models\Docente;
use Modules\Frequencia\DTO\AulaPrevista;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Registo;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;

/** Leitura dos ecrãs da frequência: aulas do dia do professor, uma chamada, pendentes da direcção, faltas do aluno. */
class ConsultaFrequenciaService
{
    public function __construct(
        private AulasPrevistasService $previstas,
        private ChamadasPorFazerService $porFazer,
        private ConsultaDocenciaParaHorario $docencia,
        private ConsultaFaltasDoAluno $faltas,
        private JanelaDeCorreccaoService $janela,
    ) {
    }

    /**
     * As aulas previstas do professor nesse dia, com o estado da chamada: pendente, feita ou não leccionada.
     *
     * @return list<array<string, mixed>>
     */
    public function aulasDoDia(User $utilizador, CarbonInterface $data): array
    {
        $pares = $this->docencia->paresEmVigorDoUtilizador($utilizador->id, $data);
        return $this->comEstado($this->previstas->dosDocentes($pares, $data, $data), $data);
    }

    /**
     * @param  list<AulaPrevista>  $previstas
     * @return list<array<string, mixed>>
     */
    private function comEstado(array $previstas, CarbonInterface $data): array
    {
        if ($previstas === []) {
            return [];
        }

        $feitas = Aula::query()
            ->whereIn('turma_id', array_unique(array_column($previstas, 'turma_id')))
            ->whereDate('data', $data->toDateString())
            ->get()
            ->keyBy(fn (Aula $a) => $a->turma_id . '|' . ($a->horario_id ?? 'dia'));

        $linhas = $this->enriquecer($previstas, fn (AulaPrevista $p) => $feitas[$p->turma_id . '|' . ($p->horario_id ?? 'dia')] ?? null);
        usort($linhas, fn (array $a, array $b) => [$a['horario_inicio'] ?? '', $a['turma_rotulo']] <=> [$b['horario_inicio'] ?? '', $b['turma_rotulo']]);

        return $linhas;
    }

    /**
     * As aulas previstas de uma turma num dia, com o estado da chamada (vista da secretaria).
     *
     * @return list<array<string, mixed>>
     */
    public function aulasDaTurmaNoDia(int $turmaId, CarbonInterface $data): array
    {
        return $this->comEstado($this->previstas->daTurmaNoDia($turmaId, $data), $data);
    }

    /**
     * A chamada de uma aula: cabeçalho, alunos por ordem alfabética e se o utilizador a pode editar.
     *
     * @return array{aula: array<string, mixed>, registos: list<array<string, mixed>>, editavel: bool, fora_da_janela: bool}
     */
    public function chamada(Aula $aula, User $utilizador): array
    {
        $aula->loadMissing(['turma.nivelAcademico', 'turma.turno', 'turma.anoLectivo', 'horario', 'disciplina']);

        $registos = $aula->registos()->with(['matricula.aluno.dadosPessoa', 'justificacao'])->get()
            ->map(fn (Registo $r) => [
                'registo_id' => $r->id,
                'matricula_id' => (int) $r->matricula_id,
                'nome' => (string) $r->matricula?->aluno?->dadosPessoa?->nome_completo,
                'estado' => $r->estado->value,
                'observacao' => $r->observacao,
                'justificada' => $r->justificacao !== null,
                'motivo_justificacao' => $r->justificacao?->motivo,
                'documento_referencia' => $r->justificacao?->documento_referencia,
            ])
            ->sortBy(fn (array $r) => mb_strtolower($r['nome']))
            ->values()
            ->all();

        return [
            'aula' => [
                'id' => $aula->id,
                'data' => $aula->data->toDateString(),
                'tipo' => $aula->tipo->value,
                'turma_id' => (int) $aula->turma_id,
                'turma_rotulo' => (string) $aula->turma?->rotulo(completo: true),
                'horario_nome' => $aula->horario?->nome,
                'disciplina_nome' => $aula->disciplina?->nome,
                'estado' => $aula->estado->value,
                'estado_descricao' => $aula->estado_descricao,
                'motivo_nao_leccionada' => $aula->motivo_nao_leccionada,
            ],
            'registos' => $registos,
            'editavel' => $aula->estado === EstadoAula::REALIZADA
                && ($this->janela->dentroDaJanela($aula->data) || $utilizador->can('frequencia.corrigir-fora-prazo')),
            // Quem corrige fora da janela tem de indicar o motivo ao guardar.
            'fora_da_janela' => ! $this->janela->dentroDaJanela($aula->data),
        ];
    }

    /**
     * As aulas previstas dos últimos 7 dias sem chamada (todas as turmas activas ou uma), com o docente.
     *
     * @return list<array<string, mixed>>
     */
    public function pendentes(?int $turmaId): array
    {
        $linhas = $this->enriquecer($this->porFazer->previstasSemChamada($turmaId), fn () => null);
        usort($linhas, fn (array $a, array $b) => [$b['data'], $a['turma_rotulo']] <=> [$a['data'], $b['turma_rotulo']]);

        return $linhas;
    }

    /**
     * Um bloco por matrícula do aluno da sessão (mais recente primeiro), com o resumo das faltas.
     *
     * @return list<array<string, mixed>>
     */
    public function minhasFaltas(User $utilizador): array
    {
        $aluno = $utilizador->dados_pessoa_id === null ? null : Aluno::query()->where('dados_pessoa_id', $utilizador->dados_pessoa_id)->first();

        if ($aluno === null) {
            return [];
        }

        return Matricula::query()->where('aluno_id', $aluno->id)->with(['anoLectivo:id,nome', 'turma.nivelAcademico', 'turma.turno', 'turma.anoLectivo'])
            ->orderByDesc('ano_lectivo_id')->get()
            ->map(function (Matricula $m) {
                $resumo = $this->faltas->resumoDaMatricula($m->id);
                $nomes = Disciplina::query()->whereIn('id', array_keys($resumo['por_disciplina']))->pluck('nome', 'id');

                return [
                    'matricula_id' => $m->id,
                    'ano_lectivo' => (string) $m->anoLectivo?->nome,
                    'turma' => (string) $m->turma?->rotulo(),
                    'resumo' => $resumo,
                    'por_disciplina' => collect($resumo['por_disciplina'])
                        ->map(fn (array $d, int $id) => ['disciplina_id' => $id, 'nome' => (string) ($nomes[$id] ?? '')] + $d)
                        ->sortBy('nome')
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }

    /**
     * @param  list<AulaPrevista>  $previstas
     * @param  callable(AulaPrevista): ?Aula  $aulaDe
     * @return list<array<string, mixed>>
     */
    private function enriquecer(array $previstas, callable $aulaDe): array
    {
        $turmas = Turma::query()->with(['nivelAcademico:id,nome', 'turno:id,nome', 'anoLectivo:id,nome'])->whereIn('id', array_unique(array_column($previstas, 'turma_id')))->get()->keyBy('id');
        $disciplinas = Disciplina::query()->whereIn('id', array_filter(array_column($previstas, 'disciplina_id')))->pluck('nome', 'id');
        $horarios = Horario::query()->whereIn('id', array_filter(array_column($previstas, 'horario_id')))->get()->keyBy('id');
        $docentes = Docente::query()->with('dadosPessoa:id,nome_completo')->whereIn('id', array_filter(array_column($previstas, 'docente_id')))->get()
            ->mapWithKeys(fn (Docente $d) => [$d->id => (string) $d->dadosPessoa?->nome_completo]);

        return array_map(function (AulaPrevista $p) use ($turmas, $disciplinas, $horarios, $docentes, $aulaDe) {
            $aula = $aulaDe($p);
            $horario = $p->horario_id === null ? null : $horarios[$p->horario_id] ?? null;

            return [
                'turma_id' => $p->turma_id,
                'turma_rotulo' => (string) $turmas[$p->turma_id]?->rotulo(completo: true),
                'data' => $p->data,
                'tipo' => $p->tipo->value,
                'horario_id' => $p->horario_id,
                'horario_nome' => $horario?->nome,
                'horario_inicio' => $horario?->hora_inicio->format('H:i'),
                'horario_fim' => $horario?->hora_fim->format('H:i'),
                'disciplina_id' => $p->disciplina_id,
                'disciplina_nome' => $p->disciplina_id === null ? null : ($disciplinas[$p->disciplina_id] ?? null),
                'docente_nome' => $p->docente_id === null ? null : ($docentes[$p->docente_id] ?? null),
                'sem_docente' => $p->sem_docente,
                'estado' => $aula === null ? 'pendente' : ($aula->estado === EstadoAula::NAO_LECCIONADA ? 'nao_leccionada' : 'feita'),
                'aula_id' => $aula?->id,
            ];
        }, $previstas);
    }
}
