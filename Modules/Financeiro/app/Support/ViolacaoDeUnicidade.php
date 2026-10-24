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

    /**
     * Violação de unicidade de `unique(tenant_id, ano_lectivo_id, nome)` de `planos_propina`: a única que a alteração
     * de preço pode atribuir a concorrência (o mesmo nome de plano novo criado ao mesmo tempo).
     */
    public static function doNomeDoPlano(Throwable $e): bool
    {
        if (! self::e($e)) {
            return false;
        }

        $mensagem = $e->getMessage();

        return stripos($mensagem, 'planos_propina_tenant_id_ano_lectivo_id_nome_unique') !== false
            || (stripos($mensagem, 'UNIQUE constraint failed') !== false && stripos($mensagem, 'planos_propina.nome') !== false);
    }

    /**
     * Violação de `pagamentos_referencia_confirmada_unique` (mesma referência no mesmo método entre Confirmados): a
     * única unicidade do registo de pagamentos que se atribui a concorrência (duas sessões com a mesma referência).
     */
    public static function daReferenciaDoPagamento(Throwable $e): bool
    {
        if (! self::e($e)) {
            return false;
        }

        $mensagem = $e->getMessage();

        return stripos($mensagem, 'pagamentos_referencia_confirmada_unique') !== false
            || (stripos($mensagem, 'UNIQUE constraint failed') !== false && stripos($mensagem, 'pagamentos.referencia') !== false);
    }
}
