<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('propinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('plano_propina_id')->constrained('planos_propina')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->unsignedSmallInteger('ordem');
            $table->date('periodo_inicio');
            $table->date('periodo_fim');
            $table->unsignedBigInteger('valor_original');
            $table->unsignedBigInteger('valor');
            $table->unsignedBigInteger('valor_pago')->default(0);
            $table->char('moeda', 3);
            $table->unsignedBigInteger('cambio_usd')->nullable();
            $table->date('data_vencimento');
            $table->unsignedTinyInteger('dias_tolerancia');
            $table->date('data_limite');
            $table->date('capital_liquidado_em')->nullable();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao');
            $table->unsignedTinyInteger('origem_geracao');
            $table->string('origem_geracao_descricao');
            $table->text('motivo_geracao')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelado_em')->nullable();
            $table->text('motivo_anulacao')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'matricula_id', 'periodo_inicio'], 'propinas_matricula_periodo_index');
            $table->index(['tenant_id', 'estado', 'data_vencimento'], 'propinas_estado_vencimento_index');
            $table->index(['tenant_id', 'estado', 'data_limite'], 'propinas_estado_limite_index');
            $table->index(['tenant_id', 'plano_propina_id', 'estado'], 'propinas_plano_estado_index');
            $table->index(['tenant_id', 'ano_lectivo_id', 'estado'], 'propinas_ano_estado_index');
        });

        // Cobrança dupla, camada 1: unicidades só entre propinas activas (Em Aberto, Parcialmente Paga,
        // Paga); Cancelada e Anulada ficam de fora para permitir regerar. O Blueprint não tem índices
        // parciais; a sintaxe é comum a PostgreSQL e SQLite (precedente: domains_tenant_principal_unique).
        DB::statement('CREATE UNIQUE INDEX propinas_plano_ordem_activo_unique ON propinas (tenant_id, matricula_id, plano_propina_id, ordem) WHERE estado IN (1, 2, 3)');
        DB::statement('CREATE UNIQUE INDEX propinas_periodo_activo_unique ON propinas (tenant_id, matricula_id, periodo_inicio) WHERE estado IN (1, 2, 3)');

        // SQLite não aceita ALTER TABLE … ADD CONSTRAINT: os CHECK só existem em PostgreSQL (produção).
        // O model Propina repete as mesmas regras, por isso a suite em SQLite também as exercita.
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $restricoes = [
            'propinas_valor_positivo_check' => 'valor > 0',
            'propinas_valor_original_positivo_check' => 'valor_original > 0',
            'propinas_valor_pago_intervalo_check' => 'valor_pago >= 0 AND valor_pago <= valor',
            'propinas_estado_check' => 'estado BETWEEN 1 AND 5',
            'propinas_estado_coerente_check' => '(estado = 1 AND valor_pago = 0) OR (estado = 2 AND valor_pago > 0 AND valor_pago < valor) OR (estado = 3 AND valor_pago = valor) OR (estado IN (4, 5) AND valor_pago = 0)',
            'propinas_capital_liquidado_check' => '(capital_liquidado_em IS NOT NULL) = (valor_pago = valor)',
            'propinas_cancelada_check' => '(estado = 4) = (cancelado_em IS NOT NULL)',
            'propinas_anulada_check' => '(estado = 5) = (anulado_em IS NOT NULL)',
            'propinas_cancelada_motivo_check' => "(estado <> 4 OR (motivo_cancelamento IS NOT NULL AND btrim(motivo_cancelamento) <> '')) AND (cancelado_por IS NULL OR estado = 4)",
            'propinas_anulada_motivo_check' => "(estado <> 5 OR (motivo_anulacao IS NOT NULL AND btrim(motivo_anulacao) <> '')) AND (anulado_por IS NULL OR estado = 5)",
            'propinas_tolerancia_check' => 'dias_tolerancia >= 0',
            'propinas_data_limite_check' => 'data_limite = data_vencimento + dias_tolerancia',
            'propinas_ordem_check' => 'ordem >= 1',
            'propinas_periodo_check' => 'periodo_fim >= periodo_inicio',
            'propinas_vencimento_check' => 'data_vencimento >= periodo_inicio AND data_limite >= data_vencimento',
            'propinas_origem_geracao_check' => 'origem_geracao BETWEEN 1 AND 4',
            'propinas_moeda_check' => 'char_length(moeda) = 3',
            'propinas_cambio_check' => 'cambio_usd IS NULL OR cambio_usd > 0',
        ];

        foreach ($restricoes as $nome => $expressao) {
            DB::statement("ALTER TABLE propinas ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('propinas');
    }
};
