<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;

/**
 * Fonte única da regra de quem pode lançar avaliação (notas por números, níveis qualitativos e competências). Tudo vem
 * do motor de permissões, nada é decidido pelo papel do utilizador:
 * - Dentro do prazo: o docente atribuído à turma e disciplina, com a permissão de criar ou editar indicada pelo chamador.
 * - Depois do prazo: só quem tem `nota.lancar-fora-prazo`, e com motivo. Sem essa permissão, o prazo é definitivo.
 * - Quem tem `nota.lancar-fora-prazo` também lança dentro do prazo, sem ser docente atribuído.
 */
class AutorizaLancamentoService
{
    public function __construct(private VerificaAtribuicaoDocente $atribuicoes)
    {
    }

    /**
     * @param list<string> $permissoes basta ter uma delas (ex.: ['nota.criar'] para nota nova, ['nota.editar'] para alterar)
     */
    public function exigir(int $turmaId, int $disciplinaId, bool $foraDoPrazo, ?string $motivo, array $permissoes = ['nota.criar', 'nota.editar']): void
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
            && Gate::any($permissoes)
            && $this->atribuicoes->podeGerir($utilizador->id, $turmaId, $disciplinaId);

        if (! $podeNaTurma && ! $temExcepcao) {
            throw new AuthorizationException('Não lecciona esta disciplina nesta turma.');
        }
    }
}
