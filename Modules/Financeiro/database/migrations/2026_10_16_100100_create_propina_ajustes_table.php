<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Só acrescenta (o model recusa update/delete): reverter é uma nova alteração com o seu motivo.
        Schema::create('propina_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('alteracao_preco_id')->constrained('alteracoes_preco_plano')->restrictOnDelete();
            $table->foreignId('propina_id')->constrained('propinas')->restrictOnDelete();
            $table->unsignedBigInteger('valor_anterior');
            $table->unsignedBigInteger('valor_novo');
            $table->unsignedBigInteger('valor_pago_no_momento');
            $table->foreignId('plano_anterior_id')->constrained('planos_propina')->restrictOnDelete();
            $table->foreignId('plano_novo_id')->nullable()->constrained('planos_propina')->restrictOnDelete();
            $table->text('motivo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['alteracao_preco_id', 'propina_id'], 'propina_ajustes_alteracao_propina_unique');
            $table->index(['tenant_id', 'propina_id', 'id'], 'propina_ajustes_propina_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'propina_ajustes_valores_check' => 'valor_anterior > 0 AND valor_novo > 0 AND valor_novo <> valor_anterior',
            'propina_ajustes_valor_pago_check' => 'valor_pago_no_momento >= 0 AND valor_pago_no_momento <= valor_novo',
            'propina_ajustes_motivo_check' => "btrim(motivo) <> ''",
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE propina_ajustes ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }

        // Imutável (spec §14): nem o model nem SQL cru alteram ou apagam um ajuste.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION propina_ajustes_imutavel() RETURNS trigger AS $$
            BEGIN
                -- A única escrita tolerada: o ON DELETE SET NULL de `criado_por` quando se apaga o utilizador.
                IF TG_OP = 'UPDATE' AND OLD.criado_por IS NOT NULL AND NEW.criado_por IS NULL
                    AND (to_jsonb(NEW) - 'criado_por') = (to_jsonb(OLD) - 'criado_por') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'propina_ajustes é imutável: reverter é uma nova alteração' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER propina_ajustes_imutavel BEFORE UPDATE OR DELETE ON propina_ajustes FOR EACH ROW EXECUTE FUNCTION propina_ajustes_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('propina_ajustes');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS propina_ajustes_imutavel()');
        }
    }
};
