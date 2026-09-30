<?php

namespace Modules\Core\Support;

use Illuminate\Database\Query\Builder;

/**
 * Pesquisa "contém" partilhada por todas as listagens: `whereContem` e
 * `orWhereContem` no query builder (e, por herança, no Eloquent).
 *
 * - Ignora maiúsculas/minúsculas: em Postgres `like` distingue-as, por isso
 *   usa-se `ilike` (nos outros motores, `like` já as ignora).
 * - Escapa `%`, `_` e `\` do termo: o que o utilizador escreve é texto, não
 *   padrão — pesquisar "100%" não pode devolver tudo o que contém "100".
 */
class PesquisaTexto
{
    public static function registar(): void
    {
        Builder::macro('whereContem', function (string $coluna, string $termo, string $boolean = 'and') {
            /** @var Builder $this */
            $operador = $this->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            return $this->whereRaw(
                $this->getGrammar()->wrap($coluna)." {$operador} ? escape '\\'",
                ['%'.PesquisaTexto::escapar($termo).'%'],
                $boolean,
            );
        });

        Builder::macro('orWhereContem', function (string $coluna, string $termo) {
            /** @var Builder $this */
            return $this->whereContem($coluna, $termo, 'or');
        });
    }

    public static function escapar(string $termo): string
    {
        return addcslashes($termo, '\\%_');
    }
}
