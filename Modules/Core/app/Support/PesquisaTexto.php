<?php

namespace Modules\Core\Support;

use Illuminate\Database\Query\Builder;

/**
 * Pesquisa "contém" partilhada por todas as listagens: `whereContem` e
 * `orWhereContem` no query builder (e, por herança, no Eloquent).
 *
 * - Ignora maiúsculas/minúsculas: em Postgres `like` distingue-as, por isso
 *   usa-se `ilike` (nos outros motores, `like` já as ignora).
 * - Escapa `%`, `_` e o próprio carácter de escape do termo: o que o utilizador
 *   escreve é texto, não padrão — pesquisar "100%" não pode devolver tudo o que
 *   contém "100".
 * - O carácter de escape é `!` e NÃO a barra invertida. Com `escape '\'` o PDO
 *   do PostgreSQL trata `\'` como aspa escapada e o literal engole os `?`
 *   seguintes: com duas ou mais colunas dá "Invalid parameter number: parameter
 *   was not defined". Com `!` não há ambiguidade em nenhum motor.
 */
class PesquisaTexto
{
    /** Carácter de escape do LIKE. Nunca a barra invertida (ver o comentário da classe). */
    public const ESCAPE = '!';

    public static function registar(): void
    {
        Builder::macro('whereContem', function (string $coluna, string $termo, string $boolean = 'and') {
            /** @var Builder $this */
            $operador = $this->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            return $this->whereRaw(
                $this->getGrammar()->wrap($coluna)." {$operador} ? escape '".PesquisaTexto::ESCAPE."'",
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
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $termo,
        );
    }
}
