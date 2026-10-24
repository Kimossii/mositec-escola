<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Histórico de uma multa (spec Propinas §15.2): só se acrescenta (o model recusa update/delete). Uma
        // revisão AUTOMATICA regista cada escalão aplicado (ordem + data_referencia = L_k, sem motivo nem autor);
        // uma MANUAL regista a isenção/redução (motivo obrigatório, autor, sem escalão nem data_referencia).
        Schema::create('propina_multa_revisoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('propina_multa_id')->constrained('propina_multas')->restrictOnDelete();
            $table->unsignedTinyInteger('origem');
            $table->unsignedTinyInteger('escalao_ordem')->nullable();
            $table->unsignedBigInteger('valor_anterior');
            $table->unsignedBigInteger('valor_novo');
            $table->date('data_referencia')->nullable();
            $table->text('motivo')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'propina_multa_id'], 'propina_multa_revisoes_multa_index');
        });

        // Cada escalão produz no máximo uma revisão automática (origem = 0). Sintaxe comum a SQLite e PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX propina_multa_revisoes_escalao_automatica_unique ON propina_multa_revisoes (propina_multa_id, escalao_ordem) WHERE origem = 0');

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'propina_multa_revisoes_origem_check' => 'origem IN (0, 1)',
            'propina_multa_revisoes_valores_check' => 'valor_anterior >= 0 AND valor_novo >= 0',
            'propina_multa_revisoes_forma_check' => "(origem = 0 AND escalao_ordem IS NOT NULL AND data_referencia IS NOT NULL AND motivo IS NULL AND criado_por IS NULL) OR (origem = 1 AND escalao_ordem IS NULL AND data_referencia IS NULL AND btrim(coalesce(motivo, '')) <> '')",
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE propina_multa_revisoes ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }

        // Imutável (spec §15.2): só se acrescenta. A única escrita tolerada é o ON DELETE SET NULL de criado_por.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION propina_multa_revisoes_imutavel() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.criado_por IS NOT NULL AND NEW.criado_por IS NULL
                    AND (to_jsonb(NEW) - 'criado_por') = (to_jsonb(OLD) - 'criado_por') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'propina_multa_revisoes é imutável: só se acrescenta' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER propina_multa_revisoes_imutavel BEFORE UPDATE OR DELETE ON propina_multa_revisoes FOR EACH ROW EXECUTE FUNCTION propina_multa_revisoes_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('propina_multa_revisoes');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS propina_multa_revisoes_imutavel()');
        }
    }
};
