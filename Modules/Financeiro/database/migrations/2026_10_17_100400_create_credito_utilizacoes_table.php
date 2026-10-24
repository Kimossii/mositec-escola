<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uso de um crédito numa propina (spec Pagamentos §3, §6). Pagamentos-B acrescenta propina_multa_id + XOR.
        // pagamento_id (O8): o pagamento em cujo acto a utilização foi combinada; NULL na aplicação isolada (D12). Imutável.
        Schema::create('credito_utilizacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('credito_id')->constrained('creditos')->restrictOnDelete();
            $table->foreignId('propina_id')->constrained('propinas')->restrictOnDelete();
            $table->foreignId('pagamento_id')->nullable()->constrained('pagamentos')->restrictOnDelete();
            $table->unsignedBigInteger('valor');
            $table->date('data_utilizacao');
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao');
            $table->text('motivo_anulacao')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'credito_id', 'estado'], 'credito_utilizacoes_credito_estado_index');
            $table->index(['tenant_id', 'propina_id'], 'credito_utilizacoes_propina_index');
            $table->index(['tenant_id', 'pagamento_id'], 'credito_utilizacoes_pagamento_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'credito_utilizacoes_valor_check' => 'valor > 0',
            'credito_utilizacoes_estado_check' => 'estado IN (1, 2)',
            'credito_utilizacoes_anulacao_check' => '(estado = 2) = (anulado_em IS NOT NULL)',
            'credito_utilizacoes_motivo_check' => "estado <> 2 OR btrim(coalesce(motivo_anulacao, '')) <> ''",
            'credito_utilizacoes_anulado_por_check' => 'estado = 2 OR anulado_por IS NULL',
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE credito_utilizacoes ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION credito_utilizacoes_imutavel() RETURNS trigger AS $$
            DECLARE
                autoria text[] := ARRAY['criado_por', 'anulado_por', 'updated_at'];
                anulacao text[] := ARRAY['estado', 'estado_descricao', 'motivo_anulacao', 'anulado_por', 'anulado_em', 'updated_at'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'credito_utilizacoes: uma utilização nunca se elimina (anule-a)' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF (to_jsonb(NEW) - autoria) = (to_jsonb(OLD) - autoria)
                    AND (NEW.criado_por IS NULL OR NEW.criado_por IS NOT DISTINCT FROM OLD.criado_por)
                    AND (NEW.anulado_por IS NULL OR NEW.anulado_por IS NOT DISTINCT FROM OLD.anulado_por) THEN
                    RETURN NEW;
                END IF;
                IF OLD.estado = 1 AND NEW.estado = 2 AND (to_jsonb(NEW) - anulacao) = (to_jsonb(OLD) - anulacao) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'credito_utilizacoes: uma utilização só passa de Activa a Anulada' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER credito_utilizacoes_imutavel BEFORE UPDATE OR DELETE ON credito_utilizacoes FOR EACH ROW EXECUTE FUNCTION credito_utilizacoes_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_utilizacoes');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS credito_utilizacoes_imutavel()');
        }
    }
};
