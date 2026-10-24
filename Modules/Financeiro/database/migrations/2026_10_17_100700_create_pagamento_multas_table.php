<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Distribuição de um pagamento por uma multa (spec Pagamentos §3). Só se cria; conta como pago apenas
        // enquanto o pagamento estiver Confirmado.
        Schema::create('pagamento_multas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('pagamento_id')->constrained('pagamentos')->restrictOnDelete();
            $table->foreignId('propina_multa_id')->constrained('propina_multas')->restrictOnDelete();
            $table->unsignedBigInteger('valor');
            $table->timestamp('created_at')->nullable();

            $table->unique(['pagamento_id', 'propina_multa_id'], 'pagamento_multas_pagamento_multa_unique');
            $table->index(['tenant_id', 'propina_multa_id'], 'pagamento_multas_multa_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pagamento_multas ADD CONSTRAINT pagamento_multas_valor_check CHECK (valor > 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pagamento_multas_imutavel() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'pagamento_multas é imutável: anula-se o pagamento' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER pagamento_multas_imutavel BEFORE UPDATE OR DELETE ON pagamento_multas FOR EACH ROW EXECUTE FUNCTION pagamento_multas_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamento_multas');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS pagamento_multas_imutavel()');
        }
    }
};
