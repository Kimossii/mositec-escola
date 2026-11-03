<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;

/**
 * Quem pode lançar avaliação qualitativa (nível da área ou de uma competência). Dentro do prazo: o docente atribuído à
 * turma e área, com `nota.criar` ou `nota.editar`. Depois do prazo: só quem tem `nota.lancar-fora-prazo`, com motivo. Quem
 * tem essa permissão também lança dentro do prazo, sem ser docente atribuído.
 */
class AutorizaLancamentoService
{
    public function __construct(private VerificaAtribuicaoDocente $atribuicoes)
    {
    }

    public function exigir(int $turmaId, int $disciplinaId, bool $foraDoPrazo, ?string $motivo): void
    {
        $utilizador = auth()->user();
        $temExcepcao = Gate::allows('nota.lancar-fora-prazo');

        if ($foraDoPrazo) {
            if (! $temExcepcao) {
                throw new AuthorizationException('O prazo de lançamento terminou.');
            }
            if ($motivo === null || trim($motivo) === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo do lançamento fora do prazo.']);
            }

            return;
        }

        $podeNaTurma = $utilizador !== null
            && (Gate::allows('nota.criar') || Gate::allows('nota.editar'))
            && $this->atribuicoes->podeGerir($utilizador->id, $turmaId, $disciplinaId);

        if (! $podeNaTurma && ! $temExcepcao) {
            throw new AuthorizationException('Não lecciona esta área nesta turma.');
        }
    }
}
