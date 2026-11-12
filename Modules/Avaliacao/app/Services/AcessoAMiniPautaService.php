<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Usuario\Models\User;

/**
 * Quem pode abrir e mexer na mini-pauta de uma turma e disciplina: o docente atribuído, ou quem tem as permissões de excepção
 * (lançar fora do prazo ou anular), que vêem todas as turmas do ano activo.
 */
class AcessoAMiniPautaService
{
    public function __construct(private VerificaAtribuicaoDocente $docencia)
    {
    }

    public function pode(User $utilizador, int $turmaId, int $disciplinaId): bool
    {
        return $this->docencia->podeGerir($utilizador->id, $turmaId, $disciplinaId) || $this->temAcessoAmplo($utilizador);
    }

    /** @throws AuthorizationException */
    public function exigir(User $utilizador, int $turmaId, int $disciplinaId): void
    {
        if (! $this->pode($utilizador, $turmaId, $disciplinaId)) {
            throw new AuthorizationException('Não lecciona esta disciplina nesta turma.');
        }
    }

    /**
     * O mesmo, para o utilizador da sessão, a chamar logo à entrada de cada escrita: o acesso decide-se antes de qualquer
     * validação, para um erro nunca revelar dados de uma turma que o utilizador não lecciona.
     *
     * @throws AuthorizationException
     */
    public function exigirDoUtilizadorActual(int $turmaId, int $disciplinaId): void
    {
        $utilizador = auth()->user();

        if ($utilizador === null) {
            throw new AuthorizationException('Sem sessão iniciada.');
        }

        $this->exigir($utilizador, $turmaId, $disciplinaId);
    }

    public function temAcessoAmplo(User $utilizador): bool
    {
        $gate = Gate::forUser($utilizador);

        return $gate->allows('nota.lancar-fora-prazo') || $gate->allows('nota.anular');
    }
}
