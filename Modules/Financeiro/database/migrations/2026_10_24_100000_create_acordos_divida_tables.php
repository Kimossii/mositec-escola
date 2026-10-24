<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Acordo de pagamento em prestações de uma matrícula. O cumprimento calcula-se pelo que falta pagar nas propinas incluídas.
        Schema::create('acordos_divida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->restrictOnDelete();
            $table->string('moeda', 3);
            $table->unsignedBigInteger('divida_inicial'); // capital + multas antes do acordo
            $table->unsignedSmallInteger('desconto_multa_pct')->default(0); // % perdoada nas multas (0–100)
            $table->unsignedBigInteger('desconto'); // valor perdoado
            $table->unsignedBigInteger('total'); // divida_inicial − desconto: o que fica por pagar em prestações
            $table->unsignedTinyInteger('estado')->default(1); // 1 activo, 2 cancelado, 3 quebrado
            $table->string('estado_descricao')->default('Activo');
            $table->text('motivo');
            $table->text('motivo_encerramento')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'matricula_id', 'estado']);
        });

        Schema::create('acordo_propinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('acordo_id')->constrained('acordos_divida')->restrictOnDelete();
            $table->foreignId('propina_id')->constrained('propinas')->restrictOnDelete();
            $table->unsignedBigInteger('capital_inicial');
            $table->unsignedBigInteger('multa_inicial');
            $table->timestamps();

            $table->unique(['acordo_id', 'propina_id']);
            $table->index(['tenant_id', 'propina_id']);
        });

        Schema::create('acordo_prestacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('acordo_id')->constrained('acordos_divida')->restrictOnDelete();
            $table->unsignedSmallInteger('ordem');
            $table->date('data_vencimento');
            $table->unsignedBigInteger('valor');
            $table->timestamps();

            $table->unique(['acordo_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acordo_prestacoes');
        Schema::dropIfExists('acordo_propinas');
        Schema::dropIfExists('acordos_divida');
    }
};
