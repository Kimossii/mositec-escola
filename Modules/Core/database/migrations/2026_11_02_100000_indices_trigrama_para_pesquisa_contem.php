<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `whereContem` faz `ilike '%termo%'`: com `%` à frente, um índice normal não serve e cada pesquisa lê a tabela inteira.
 * Os índices de trigramas (GIN + pg_trgm) servem esse padrão. Só as colunas que as pesquisas das listas e a pesquisa
 * global mais usam; só em PostgreSQL.
 */
return new class extends Migration
{
    private const INDICES = [
        'dados_pessoas_nome_completo_trgm' => ['dados_pessoas', 'nome_completo'],
        'alunos_numero_matricula_trgm' => ['alunos', 'numero_matricula'],
        'matriculas_numero_registo_trgm' => ['matriculas', 'numero_registo_matricula'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (self::INDICES as $nome => [$tabela, $coluna]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$nome} ON {$tabela} USING gin ({$coluna} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::INDICES) as $nome) {
            DB::statement("DROP INDEX IF EXISTS {$nome}");
        }
    }
};
