<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pagamentos-B / spec Pagamentos §6: uma utilização de crédito pode liquidar uma propina OU uma multa
        // (propina_id XOR propina_multa_id), com a mesma ordem capital → multa. `propina_id` passa a nullable.
        Schema::table('credito_utilizacoes', function (Blueprint $table) {
            $table->foreignId('propina_multa_id')->nullable()->after('propina_id')->constrained('propina_multas')->restrictOnDelete();
        });

        Schema::table('credito_utilizacoes', function (Blueprint $table) {
            $table->foreignId('propina_id')->nullable()->change();
        });

        Schema::table('credito_utilizacoes', function (Blueprint $table) {
            $table->index(['tenant_id', 'propina_multa_id'], 'credito_utilizacoes_multa_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE credito_utilizacoes ADD CONSTRAINT credito_utilizacoes_xor_check CHECK ((propina_id IS NOT NULL) <> (propina_multa_id IS NOT NULL))');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE credito_utilizacoes DROP CONSTRAINT IF EXISTS credito_utilizacoes_xor_check');
        }

        Schema::table('credito_utilizacoes', function (Blueprint $table) {
            $table->dropForeign(['propina_multa_id']);
            $table->dropIndex('credito_utilizacoes_multa_index');
            $table->dropColumn('propina_multa_id');
        });

        Schema::table('credito_utilizacoes', function (Blueprint $table) {
            $table->foreignId('propina_id')->nullable(false)->change();
        });
    }
};
