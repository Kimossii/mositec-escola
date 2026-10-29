<?php

namespace Modules\Docente\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;

/** Traduz a violação das constraints de exclusão de `atribuicoes_docentes` (concorrência, PostgreSQL) em erro de validação. */
final class SobreposicaoDeAtribuicao
{
    /** PostgreSQL: SQLSTATE 23P01 (exclusion_violation). */
    private const SQLSTATE_EXCLUSAO = '23P01';

    /** Constraints de exclusão → [campo, mensagem do validador]. */
    private const CONSTRAINTS = [
        'atribuicoes_docentes_professor_excl' => ['professor_id', ValidadorAtribuicaoDocenteService::MENSAGEM_PROFESSOR_SOBREPOSTO],
        'atribuicoes_docentes_um_titular_excl' => ['data_inicio', ValidadorAtribuicaoDocenteService::MENSAGEM_TITULAR_SOBREPOSTO],
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
