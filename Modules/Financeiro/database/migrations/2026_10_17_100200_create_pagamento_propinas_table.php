<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Distribuição do dinheiro de um pagamento pelas propinas (spec Pagamentos §3). Só se cria; conta como pago
        // apenas enquanto o pagamento estiver Confirmado.
        Schema::create('pagamento_propinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('pagamento_id')->constrained('pagamentos')->restrictOnDelete();
            $table->foreignId('propina_id')->constrained('propinas')->restrictOnDelete();
            $table->unsignedBigInteger('valor');
            $table->timestamp('created_at')->nullable();

            $table->unique(['pagamento_id', 'propina_id'], 'pagamento_propinas_pagamento_propina_unique');
            $table->index(['tenant_id', 'propina_id'], 'pagamento_propinas_propina_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pagamento_propinas ADD CONSTRAINT pagamento_propinas_valor_check CHECK (valor > 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pagamento_propinas_imutavel() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'pagamento_propinas é imutável: anula-se o pagamento' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER pagamento_propinas_imutavel BEFORE UPDATE OR DELETE ON pagamento_propinas FOR EACH ROW EXECUTE FUNCTION pagamento_propinas_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamento_propinas');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS pagamento_propinas_imutavel()');
        }
    }
};
