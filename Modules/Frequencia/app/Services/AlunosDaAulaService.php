<?php

namespace Modules\Frequencia\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;

/**
 * A lista de uma chamada (desenho D11): matrículas da turma que, NA DATA da aula, estavam activas (o estado de então,
 * pelo histórico da matrícula: um aluno transferido ou trancado depois da aula ainda faz parte dela) e já tinham
 * começado e ainda não tinham terminado; no regime tempo, só os inscritos na disciplina. Trancadas, pendentes e
 * canceladas na data ficam de fora.
 */
class AlunosDaAulaService
{
    /** @return Collection<int, Matricula> por ordem alfabética do nome do aluno, com `aluno.dadosPessoa` carregado */
    public function daAula(int $turmaId, CarbonInterface $data, TipoAula $tipo, ?int $disciplinaId): Collection
    {
        $dia = $data->toDateString();

        $candidatas = Matricula::query()
            ->where('turma_id', $turmaId)
            ->whereDate('data_matricula', '<=', $dia)
            ->where(fn (Builder $q) => $q->whereNull('data_fim')->orWhereDate('data_fim', '>=', $dia))
            ->when($tipo === TipoAula::TEMPO, fn (Builder $q) => $q->whereHas('inscricoesDisciplinas', fn (Builder $i) => $i
                ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA->value)
                ->whereHas('planoCurricularDisciplina', fn (Builder $p) => $p->where('disciplina_id', $disciplinaId))))
            ->with('aluno.dadosPessoa')
            ->get();

        $historico = MatriculaHistorico::query()
            ->whereIn('matricula_id', $candidatas->pluck('id'))
            ->orderBy('data_efeito')
            ->orderBy('id')
            ->get()
            ->groupBy('matricula_id');

        return $candidatas
            ->filter(fn (Matricula $m) => $this->estadoNaData($m, $historico[$m->id] ?? collect(), $dia) === EstadoMatriculaEnum::ACTIVA)
            ->sortBy(fn (Matricula $m) => mb_strtolower((string) $m->aluno?->dadosPessoa?->nome_completo))
            ->values();
    }

    /**
     * O estado da matrícula nesse dia: o novo estado da última mudança com efeito até essa data; se todas as mudanças
     * são posteriores, o estado anterior da primeira; sem histórico, o estado actual.
     *
     * @param  Collection<int, MatriculaHistorico>  $mudancas  por ordem cronológica
     */
    private function estadoNaData(Matricula $matricula, Collection $mudancas, string $dia): EstadoMatriculaEnum
    {
        if ($mudancas->isEmpty()) {
            return $matricula->estado;
        }

        $ate = $mudancas->filter(fn (MatriculaHistorico $h) => $h->data_efeito === null || $h->data_efeito->toDateString() <= $dia);

        return $ate->isEmpty() ? $mudancas->first()->estado_anterior : $ate->last()->estado_novo;
    }
}
