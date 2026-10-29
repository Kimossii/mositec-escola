<?php

namespace Modules\Docente\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Estabelecimento\Services\RelogioDoTenant;

/**
 * Implementa o contrato do Core. A vigência efectiva é [data_inicio, min(data_fim, fim do ano lectivo)]:
 * o scope `emVigor` do model é cru, o corte pelo fim do ano lectivo aplica-se aqui.
 */
class VerificaAtribuicaoDocenteService implements VerificaAtribuicaoDocente
{
    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    public function podeGerir(int $professorId, int $turmaId, int $disciplinaId, ?CarbonInterface $data = null): bool
    {
        return $this->emVigorEfectivo($data ?? $this->relogio->hoje())
            ->where('professor_id', $professorId)
            ->where('turma_id', $turmaId)
            ->where('disciplina_id', $disciplinaId)
            ->exists();
    }

    public function atribuicoesEmVigorDoProfessor(int $professorId, ?int $anoLectivoId = null): array
    {
        return $this->emVigorEfectivo($this->relogio->hoje())
            ->where('professor_id', $professorId)
            ->when($anoLectivoId !== null, fn (Builder $q) => $q->where('ano_lectivo_id', $anoLectivoId))
            ->with(['turma:id,nome', 'disciplina:id,nome'])
            ->orderBy('data_inicio')
            ->orderBy('id')
            ->get()
            ->map(fn (AtribuicaoDocente $a) => [
                'atribuicao_id' => $a->id,
                'turma_id' => $a->turma_id,
                'turma_nome' => (string) $a->turma?->nome,
                'disciplina_id' => $a->disciplina_id,
                'disciplina_nome' => (string) $a->disciplina?->nome,
                'papel' => $a->papel->value,
                'data_inicio' => $a->data_inicio->toDateString(),
                'data_fim' => $a->data_fim?->toDateString(),
            ])
            ->all();
    }

    /** @return Builder<AtribuicaoDocente> */
    private function emVigorEfectivo(CarbonInterface $data): Builder
    {
        $dia = $data->toDateString();

        return AtribuicaoDocente::query()
            ->emVigor($data)
            // Turma eliminada (soft delete) já não conta: o scope de SoftDeletes da Turma exclui-a.
            ->whereHas('turma')
            ->whereHas('anoLectivo', fn (Builder $ano) => $ano->whereDate('data_fim', '>=', $dia));
    }
}
