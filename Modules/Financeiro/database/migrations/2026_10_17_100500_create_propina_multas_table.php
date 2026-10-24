<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Multa por atraso de uma propina (spec Propinas §15.2): 1:1 com a propina. Guarda um snapshot do
        // escalão aplicado (sem FK para escaloes_multa) e a base de capital congelada em L₁. `valor_pago` é
        // cache do RecalcularMulta; `valor`/`estado`/`bloqueada_automatico` só mudam pelo comando ou pela isenção manual.
        Schema::create('propina_multas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('propina_id')->constrained('propinas')->restrictOnDelete();
            $table->unsignedTinyInteger('escalao_ordem');
            $table->unsignedTinyInteger('tipo');
            $table->unsignedBigInteger('valor_regra');
            $table->unsignedBigInteger('base_capital');
            $table->unsignedBigInteger('valor');
            $table->unsignedBigInteger('valor_pago')->default(0);
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao');
            $table->boolean('bloqueada_automatico')->default(false);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'propina_id'], 'propina_multas_propina_unique');
        });

        // SQLite não aceita ALTER TABLE … ADD CONSTRAINT: os CHECK só existem em PostgreSQL (produção).
        // O model PropinaMulta repete as mesmas regras, por isso a suite em SQLite também as exercita.
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'propina_multas_valor_check' => 'valor >= 0',
            'propina_multas_valor_pago_check' => 'valor_pago >= 0 AND valor_pago <= valor',
            'propina_multas_estado_check' => 'estado BETWEEN 1 AND 4',
            'propina_multas_base_capital_check' => 'base_capital > 0',
            'propina_multas_escalao_check' => 'escalao_ordem >= 1',
            'propina_multas_tipo_check' => 'tipo IN (0, 1)',
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE propina_multas ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('propina_multas');
    }
};
