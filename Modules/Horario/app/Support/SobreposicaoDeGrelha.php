<?php

namespace Modules\Horario\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Horario\Services\ValidadorGrelhaService;

/** Traduz a violação das constraints de exclusão de `horario_grelha_entradas` (concorrência, PostgreSQL) em erro de validação. */
final class SobreposicaoDeGrelha
{
    /** PostgreSQL: SQLSTATE 23P01 (exclusion_violation). */
    private const SQLSTATE_EXCLUSAO = '23P01';

    /** Constraints de exclusão → [campo, mensagem do validador]. */
    private const CONSTRAINTS = [
        'grelha_entradas_turma_excl' => ['horario_id', ValidadorGrelhaService::MENSAGEM_CONFLITO_TURMA],
        'grelha_entradas_sala_excl' => ['sala_id', ValidadorGrelhaService::MENSAGEM_CONFLITO_SALA],
    ];

    /** Só a violação de uma destas constraints é concorrência; qualquer outra falha é um defeito e propaga (devolve null). */
    public static function comoErroDeValidacao(QueryException $e): ?ValidationException
    {
        if ((string) ($e->errorInfo[0] ?? $e->getCode()) !== self::SQLSTATE_EXCLUSAO) {
            return null;
        }

        foreach (self::CONSTRAINTS as $constraint => [$campo, $mensagem]) {
            if (str_contains($e->getMessage(), $constraint)) {
                return ValidationException::withMessages([$campo => $mensagem]);
            }
        }

        return null;
    }
}
