<?php

namespace Modules\Financeiro\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Segunda linha de defesa ao eliminar configuração financeira: se a BD
 * rejeitar o delete por chave estrangeira (tabela que ReferenciasFinanceiras
 * ainda não conhece), devolve o mesmo erro amigável de validação em vez de 500.
 *
 * Usar sempre À VOLTA da operação inteira (incluindo a DB::transaction): no
 * PostgreSQL uma query falhada aborta a transação, pelo que apanhar a excepção
 * lá dentro e continuar não funciona.
 */
final class ViolacaoDeChave
{
    public const MENSAGEM = 'Não é possível eliminar: existem registos que dependem deste.';

    /** PostgreSQL: SQLSTATE 23503 (foreign_key_violation). */
    private const SQLSTATE_FK = '23503';

    public static function e(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        if ((string) ($e->errorInfo[0] ?? $e->getCode()) === self::SQLSTATE_FK) {
            return true;
        }

        // SQLite reporta SQLSTATE 23000 genérico; distingue-se pela mensagem
        // (partilhada por UNIQUE/NOT NULL, daí não bastar o código).
        return stripos($e->getMessage(), 'FOREIGN KEY constraint failed') !== false
            || stripos($e->getMessage(), 'violates foreign key constraint') !== false;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operacao
     * @return T
     *
     * @throws ValidationException
     */
    public static function comoValidacao(Closure $operacao, string $chave = 'eliminar', string $mensagem = self::MENSAGEM): mixed
    {
        try {
            return $operacao();
        } catch (QueryException $e) {
            if (! self::e($e)) {
                throw $e;
            }

            throw ValidationException::withMessages([$chave => $mensagem]);
        }
    }
}
