<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Tenancy\SequenciaPorTenant;

/**
 * Gera números sequenciais anuais (AAAA-NNNN) por tenant.
 *
 * Segurança em concorrência:
 *  - único (tenant_id, ano) na tabela: nunca existem duas linhas para o mesmo tenant e ano;
 *  - upsert idempotente: absorve a corrida do primeiro número. Em PostgreSQL, o
 *    `ON CONFLICT DO UPDATE` é quem serializa primeiro (fica com o lock da linha);
 *  - lockForUpdate seguinte: redundante mas inofensivo, mantém a leitura consistente;
 *  - increment atómico (UPDATE ... SET ultimo_numero = ultimo_numero + 1).
 *
 * Um rollback da transacção externa desfaz o incremento (não se consome número);
 * só há buraco se o gerador for chamado fora de transacção e algo falhar depois.
 *
 * Só aceita models que implementam SequenciaPorTenant: o nome da tabela vem
 * sempre dessas classes, nunca de input externo.
 */
class GeradorSequencia
{
    /** @param class-string<SequenciaPorTenant&\Illuminate\Database\Eloquent\Model> $modeloSequencia */
    public function gerar(string $modeloSequencia): string
    {
        if (! is_subclass_of($modeloSequencia, SequenciaPorTenant::class)) {
            throw new InvalidArgumentException("Sequência desconhecida: {$modeloSequencia}.");
        }

        // Sem contexto lança TenantNaoResolvido: nunca se cria uma sequência partilhada.
        $tenantId = app(TenantContext::class)->id();
        $tabela = (new $modeloSequencia())->getTable();

        return DB::transaction(function () use ($modeloSequencia, $tabela, $tenantId) {
            $ano = now()->year;

            DB::table($tabela)->upsert(
                [['tenant_id' => $tenantId, 'ano' => $ano, 'ultimo_numero' => 0, 'created_at' => now(), 'updated_at' => now()]],
                ['tenant_id', 'ano'],
                ['updated_at']
            );

            // Lido pelo model: o scope de tenant filtra pelo tenant corrente.
            $sequencia = $modeloSequencia::where('ano', $ano)->lockForUpdate()->firstOrFail();
            $sequencia->increment('ultimo_numero');

            return sprintf('%d-%04d', $ano, $sequencia->ultimo_numero);
        });
    }
}
