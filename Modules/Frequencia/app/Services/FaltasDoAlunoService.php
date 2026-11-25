<?php

namespace Modules\Frequencia\Services;

use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Contracts\ConsultaFaltasDoAluno;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Models\Registo;
use Modules\Matricula\Models\Matricula;

/** Contagem das faltas de uma matrícula (só faltas de aulas leccionadas), no total, por disciplina e por período. */
class FaltasDoAlunoService implements ConsultaFaltasDoAluno
{
    public function __construct(private RegimeDeChamada $regime)
    {
    }

    public function resumoDaMatricula(int $matriculaId): array
    {
        $matricula = Matricula::query()->with('turma.nivelAcademico')->find($matriculaId);
        $dias = $matricula?->turma !== null && $this->regime->daTurma($matricula->turma) === TipoAula::DIA;

        $faltas = $matricula === null ? collect() : Registo::query()
            ->where('frequencia_registos.matricula_id', $matriculaId)
            ->where('frequencia_registos.estado', EstadoPresenca::FALTA->value)
            ->join('frequencia_aulas', 'frequencia_aulas.id', '=', 'frequencia_registos.aula_id')
            ->where('frequencia_aulas.estado', EstadoAula::REALIZADA->value)
            ->leftJoin('frequencia_justificacoes', 'frequencia_justificacoes.registo_id', '=', 'frequencia_registos.id')
            ->get(['frequencia_aulas.data as data', 'frequencia_aulas.disciplina_id as disciplina_id', 'frequencia_justificacoes.id as justificacao_id'])
            ->map(fn ($f) => ['data' => substr((string) $f->data, 0, 10), 'disciplina_id' => $f->disciplina_id, 'justificada' => $f->justificacao_id !== null]);

        return [
            'unidade' => $dias ? 'dias' : 'aulas',
            ...$this->contar($faltas),
            'com_chamadas' => $matricula !== null && $this->temChamadas($matriculaId),
            'por_disciplina' => $dias ? [] : $faltas->whereNotNull('disciplina_id')->groupBy('disciplina_id')->map(fn ($g) => $this->contar($g))->all(),
            'por_periodo' => $matricula === null ? [] : $this->porPeriodo($matricula->ano_lectivo_id, $faltas),
        ];
    }

    /** Há algum registo da matrícula em aulas leccionadas (a escola faz chamadas). */
    private function temChamadas(int $matriculaId): bool
    {
        return Registo::query()
            ->where('frequencia_registos.matricula_id', $matriculaId)
            ->join('frequencia_aulas', 'frequencia_aulas.id', '=', 'frequencia_registos.aula_id')
            ->where('frequencia_aulas.estado', EstadoAula::REALIZADA->value)
            ->exists();
    }

    /** @return array{total: int, justificadas: int, injustificadas: int} */
    private function contar($faltas): array
    {
        $justificadas = $faltas->where('justificada', true)->count();

        return ['total' => $faltas->count(), 'justificadas' => $justificadas, 'injustificadas' => $faltas->count() - $justificadas];
    }

    /** @return list<array{periodo_id: int, nome: string, total: int, justificadas: int, injustificadas: int}> */
    private function porPeriodo(int $anoLectivoId, $faltas): array
    {
        return Periodo::query()->where('ano_lectivo_id', $anoLectivoId)->orderBy('data_inicio')->orderBy('numero')->get()
            ->map(fn (Periodo $p) => ['periodo_id' => $p->id, 'nome' => $p->nome] + $this->contar($faltas->filter(
                fn (array $f) => $f['data'] >= $p->data_inicio->toDateString() && $f['data'] <= $p->data_fim->toDateString(),
            )))
            ->all();
    }
}
