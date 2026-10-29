<?php

namespace Modules\Docente\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Usuario\Models\User;

/**
 * Implementa o contrato do Core. A vigência efectiva é [data_inicio, min(data_fim, fim do ano lectivo)]:
 * o scope `emVigor` do model é cru, o corte pelo fim do ano lectivo aplica-se aqui.
 *
 * O contrato recebe o id do UTILIZADOR (quem está autenticado); a atribuição aponta para o docente. A ponte é a
 * pessoa: `users.dados_pessoa_id` → `docentes.dados_pessoa_id`, ambas as leituras no tenant corrente. Utilizador
 * sem docente ligado (ou de outro tenant) não lecciona nada.
 */
class VerificaAtribuicaoDocenteService implements VerificaAtribuicaoDocente
{
    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    public function podeGerir(int $professorId, int $turmaId, int $disciplinaId, ?CarbonInterface $data = null): bool
    {
        $docenteId = $this->docenteDoUtilizador($professorId);

        if ($docenteId === null) {
            return false;
        }

        return $this->emVigorEfectivo($data ?? $this->relogio->hoje())
            ->where('docente_id', $docenteId)
            ->where('turma_id', $turmaId)
            ->where('disciplina_id', $disciplinaId)
            ->exists();
    }

    public function atribuicoesEmVigorDoProfessor(int $professorId, ?int $anoLectivoId = null): array
    {
        $docenteId = $this->docenteDoUtilizador($professorId);

        if ($docenteId === null) {
            return [];
        }

        return $this->emVigorEfectivo($this->relogio->hoje())
            ->where('docente_id', $docenteId)
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

    /** O docente do tenant corrente com a mesma pessoa que a conta; null se a conta não tiver pessoa ou docente. */
    private function docenteDoUtilizador(int $utilizadorId): ?int
    {
        $pessoaId = User::query()->whereKey($utilizadorId)->value('dados_pessoa_id');

        if ($pessoaId === null) {
            return null;
        }

        $docenteId = Docente::query()->where('dados_pessoa_id', $pessoaId)->value('id');

        return $docenteId === null ? null : (int) $docenteId;
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
