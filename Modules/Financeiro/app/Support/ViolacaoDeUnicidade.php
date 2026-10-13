<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Reconhece uma violação de unicidade (incluindo os índices únicos parciais de `propinas`, a camada 1
 * contra cobrança dupla). Só se usa FORA da transacção que falhou: lá dentro, em PostgreSQL, a
 * transacção já está abortada e qualquer consulta seguinte falharia.
 */
final class ViolacaoDeUnicidade
{
    /** PostgreSQL: SQLSTATE 23505 (unique_violation). */
    private const SQLSTATE_UNICIDADE = '23505';

    /** Índices únicos parciais de `propinas` (migração de F1). */
    private const INDICES_DE_PROPINAS = ['propinas_plano_ordem_activo_unique', 'propinas_periodo_activo_unique'];

    public static function e(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        if ((string) ($e->errorInfo[0] ?? $e->getCode()) === self::SQLSTATE_UNICIDADE) {
            return true;
        }

        // SQLite reporta SQLSTATE 23000 genérico (partilhado por UNIQUE, NOT NULL e FK): distingue-se pela mensagem.
        return stripos($e->getMessage(), 'UNIQUE constraint failed') !== false
            || stripos($e->getMessage(), 'duplicate key value violates unique constraint') !== false;
    }

    /**
     * Violação de unicidade de um dos índices únicos parciais de `propinas` (a camada 1 contra cobrança dupla):
     * é a única que significa «outra geração gravou o mesmo período ao mesmo tempo». Qualquer outra violação
     * de unicidade é um defeito real e não se pode disfarçar de concorrência.
     */
    public static function dasPropinas(Throwable $e): bool
    {
        if (! self::e($e)) {
            return false;
        }

        $mensagem = $e->getMessage();

        // PostgreSQL nomeia o índice violado.
        foreach (self::INDICES_DE_PROPINAS as $indice) {
            if (stripos($mensagem, $indice) !== false) {
                return true;
            }
        }

        // SQLite lista as colunas: «UNIQUE constraint failed: propinas.a, propinas.b, ...».
        return stripos($mensagem, 'UNIQUE constraint failed') !== false
            && stripos($mensagem, 'propinas.matricula_id') !== false
            && (stripos($mensagem, 'propinas.periodo_inicio') !== false || stripos($mensagem, 'propinas.ordem') !== false);
    }
}
