<?php

namespace Modules\Avaliacao\Services;

use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Usuario\Models\User;

class DocenteDoUtilizadorService
{
    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    /** Id do docente ligado ao utilizador pela pessoa (`dados_pessoa_id`), ou `null` se não for docente. */
    public function idDe(?User $utilizador): ?int
    {
        if ($utilizador === null || $utilizador->dados_pessoa_id === null) {
            return null;
        }

        return Docente::query()->where('dados_pessoa_id', $utilizador->dados_pessoa_id)->value('id');
    }

    /** Docente titular em vigor hoje na turma e disciplina, ou `null`. Serve para aplicar as excepções de prazo do docente. */
    public function titularDe(int $turmaId, int $disciplinaId): ?int
    {
        return AtribuicaoDocente::query()
            ->emVigor($this->relogio->hoje())
            ->where('turma_id', $turmaId)
            ->where('disciplina_id', $disciplinaId)
            ->where('papel', PapelDocente::TITULAR->value)
            ->value('docente_id');
    }
}
