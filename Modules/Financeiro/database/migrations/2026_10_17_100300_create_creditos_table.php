<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Excedente de um pagamento, do ALUNO (spec Pagamentos §2.3): usável em qualquer matrícula dele.
        // valor_utilizado e estado são caches do RecalcularCredito.
        Schema::create('creditos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->restrictOnDelete();
            $table->foreignId('origem_pagamento_id')->unique()->constrained('pagamentos')->restrictOnDelete();
            $table->unsignedBigInteger('valor');
            $table->unsignedBigInteger('valor_utilizado')->default(0);
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao');
            $table->timestamps();

            $table->index(['tenant_id', 'aluno_id', 'estado'], 'creditos_aluno_estado_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'creditos_valor_check' => 'valor > 0',
            'creditos_valor_utilizado_check' => 'valor_utilizado >= 0 AND valor_utilizado <= valor',
            'creditos_estado_coerente_check' => '(estado = 1 AND valor_utilizado < valor) OR (estado = 2 AND valor_utilizado = valor) OR (estado = 3 AND valor_utilizado = 0)',
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE creditos ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION creditos_imutavel() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'creditos: um crédito nunca se elimina (anula-se com o pagamento)' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.aluno_id <> OLD.aluno_id OR NEW.origem_pagamento_id <> OLD.origem_pagamento_id OR NEW.valor <> OLD.valor THEN
                    RAISE EXCEPTION 'creditos: aluno, origem e valor de um crédito nunca mudam' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF OLD.estado = 3 AND NEW.estado <> 3 THEN
                    RAISE EXCEPTION 'creditos: um crédito anulado é terminal' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER creditos_imutavel BEFORE UPDATE OR DELETE ON creditos FOR EACH ROW EXECUTE FUNCTION creditos_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('creditos');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS creditos_imutavel()');
        }
    }
};
