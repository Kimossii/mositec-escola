<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alteracoes_preco_plano', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // Snapshot do ano do plano alterado (isolamento por ano lectivo, spec §14).
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->foreignId('plano_propina_id')->constrained('planos_propina')->restrictOnDelete();
            // Nulo só no modo in loco (mes_efeito = início do plano).
            $table->foreignId('plano_novo_id')->nullable()->constrained('planos_propina')->restrictOnDelete();
            $table->unsignedTinyInteger('modo');
            $table->string('modo_descricao');
            $table->unsignedBigInteger('valor_anterior');
            $table->unsignedBigInteger('valor_novo');
            $table->date('mes_efeito');
            $table->text('motivo');
            $table->unsignedInteger('total_propinas_afectadas');
            $table->char('impressao_digital', 64);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'plano_propina_id'], 'alteracoes_preco_plano_plano_index');
            $table->index(['tenant_id', 'plano_novo_id'], 'alteracoes_preco_plano_novo_index');
            $table->index(['tenant_id', 'ano_lectivo_id'], 'alteracoes_preco_plano_ano_index');
        });

        // SQLite não aceita ALTER TABLE … ADD CONSTRAINT: os CHECK só existem em PostgreSQL (produção).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'alteracoes_preco_plano_valores_check' => 'valor_anterior > 0 AND valor_novo > 0 AND valor_novo <> valor_anterior',
            'alteracoes_preco_plano_modo_check' => 'modo IN (1, 2)',
            'alteracoes_preco_plano_mes_efeito_check' => 'EXTRACT(DAY FROM mes_efeito) = 1',
            'alteracoes_preco_plano_motivo_check' => "btrim(motivo) <> ''",
            'alteracoes_preco_plano_planos_check' => 'plano_novo_id IS NULL OR plano_novo_id <> plano_propina_id',
            'alteracoes_preco_plano_total_check' => 'total_propinas_afectadas >= 0',
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE alteracoes_preco_plano ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }

        // Imutável (spec §14): nem o model nem SQL cru alteram ou apagam um registo de alteração de preço.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION alteracoes_preco_plano_imutavel() RETURNS trigger AS $$
            BEGIN
                -- A única escrita tolerada: o ON DELETE SET NULL de `criado_por` quando se apaga o utilizador.
                IF TG_OP = 'UPDATE' AND OLD.criado_por IS NOT NULL AND NEW.criado_por IS NULL
                    AND (to_jsonb(NEW) - 'criado_por') = (to_jsonb(OLD) - 'criado_por') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'alteracoes_preco_plano é imutável: reverter é uma nova alteração' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER alteracoes_preco_plano_imutavel BEFORE UPDATE OR DELETE ON alteracoes_preco_plano FOR EACH ROW EXECUTE FUNCTION alteracoes_preco_plano_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('alteracoes_preco_plano');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS alteracoes_preco_plano_imutavel()');
        }
    }
};
