<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cobrança de produtos e serviços a uma matrícula (nunca a propinas). Estado e valor pago só os escreve RecalcularCobranca.
        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->restrictOnDelete();
            $table->string('moeda', 3);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('valor_pago')->default(0);
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Em Aberto');
            $table->unsignedTinyInteger('origem')->default(1); // 1 manual, 2 obrigatório
            $table->string('origem_descricao')->default('Manual');
            $table->date('data_limite')->nullable();
            $table->text('observacao')->nullable();
            $table->text('cancelamento_motivo')->nullable();
            $table->foreignId('cancelamento_utilizador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelamento_data')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'matricula_id']);
            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobrancas');
    }
};
