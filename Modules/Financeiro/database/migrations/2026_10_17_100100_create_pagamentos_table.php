<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dinheiro recebido de uma matrícula (spec Pagamentos §3). Só Confirmado/Anulado; nunca se edita nem se apaga.
        Schema::create('pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->restrictOnDelete();
            $table->foreignId('metodo_pagamento_id')->constrained('metodos_pagamento')->restrictOnDelete();
            $table->string('numero', 20);
            $table->unsignedBigInteger('valor');
            $table->char('moeda', 3);
            $table->unsignedBigInteger('cambio_usd')->nullable();
            $table->date('data_pagamento');
            $table->string('referencia', 100)->nullable();
            $table->text('observacao')->nullable();
            $table->string('comprovativo_path')->nullable();
            $table->string('comprovativo_nome_original')->nullable();
            $table->string('comprovativo_mime_type')->nullable();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao');
            $table->text('motivo_anulacao')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'numero'], 'pagamentos_numero_unique');
            $table->index(['tenant_id', 'matricula_id', 'data_pagamento'], 'pagamentos_matricula_data_index');
            $table->index(['tenant_id', 'aluno_id'], 'pagamentos_aluno_index');
            $table->index(['tenant_id', 'estado', 'data_pagamento'], 'pagamentos_estado_data_index');
            $table->index(['tenant_id', 'metodo_pagamento_id'], 'pagamentos_metodo_index');
        });

        // D5: a mesma referência no mesmo método só entre Confirmados (um anulado liberta-a para o pagamento correcto).
        // Sintaxe comum a SQLite e PostgreSQL (precedente: propinas_periodo_activo_unique).
        DB::statement('CREATE UNIQUE INDEX pagamentos_referencia_confirmada_unique ON pagamentos (tenant_id, metodo_pagamento_id, referencia) WHERE referencia IS NOT NULL AND estado = 1');

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'pagamentos_valor_check' => 'valor > 0',
            'pagamentos_estado_check' => 'estado IN (1, 2)',
            'pagamentos_anulacao_check' => '(estado = 2) = (anulado_em IS NOT NULL)',
            'pagamentos_motivo_anulacao_check' => "estado <> 2 OR btrim(coalesce(motivo_anulacao, '')) <> ''",
            'pagamentos_anulado_por_check' => 'estado = 2 OR anulado_por IS NULL',
            'pagamentos_numero_check' => "btrim(numero) <> ''",
            'pagamentos_cambio_check' => 'cambio_usd IS NULL OR cambio_usd > 0',
            'pagamentos_moeda_check' => 'char_length(moeda) = 3',
            'pagamentos_referencia_check' => "referencia IS NULL OR btrim(referencia) <> ''",
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE pagamentos ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }

        // D17: a única alteração de negócio é anular (1 → 2, uma vez, com os campos de anulação). Tolera o
        // ON DELETE SET NULL das colunas de autoria e gravações sem alterações de negócio (editado_por, updated_at).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pagamentos_imutavel() RETURNS trigger AS $$
            DECLARE
                autoria text[] := ARRAY['criado_por', 'editado_por', 'anulado_por', 'updated_at'];
                anulacao text[] := ARRAY['estado', 'estado_descricao', 'motivo_anulacao', 'anulado_por', 'anulado_em', 'editado_por', 'updated_at'];
                comprovativo text[] := ARRAY['comprovativo_path', 'comprovativo_nome_original', 'comprovativo_mime_type', 'editado_por', 'updated_at'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'pagamentos: um pagamento nunca se elimina (anule-o)' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF (to_jsonb(NEW) - autoria) = (to_jsonb(OLD) - autoria)
                    AND (NEW.criado_por IS NULL OR NEW.criado_por IS NOT DISTINCT FROM OLD.criado_por)
                    AND (NEW.anulado_por IS NULL OR NEW.anulado_por IS NOT DISTINCT FROM OLD.anulado_por) THEN
                    RETURN NEW;
                END IF;
                IF OLD.estado = 1 AND NEW.estado = 2 AND (to_jsonb(NEW) - anulacao) = (to_jsonb(OLD) - anulacao) THEN
                    RETURN NEW;
                END IF;
                IF OLD.estado = NEW.estado AND (to_jsonb(NEW) - comprovativo) = (to_jsonb(OLD) - comprovativo) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'pagamentos: um pagamento registado nunca se edita (anule-o e registe outro)' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER pagamentos_imutavel BEFORE UPDATE OR DELETE ON pagamentos FOR EACH ROW EXECUTE FUNCTION pagamentos_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS pagamentos_imutavel()');
        }
    }
};
