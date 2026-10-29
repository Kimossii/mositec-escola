<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('estabelecimento_id');
            $table->foreignId('dados_pessoa_id')->unique()->constrained('dados_pessoas')->restrictOnDelete();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Referência composta: o docente nunca aponta para um estabelecimento de outro tenant.
            $table->foreign(['tenant_id', 'estabelecimento_id'])
                ->references(['tenant_id', 'id'])
                ->on('estabelecimentos')
                ->restrictOnDelete();
            // Permite que outras tabelas (atribuições) referenciem o docente por (tenant_id, id).
            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docentes');
    }
};
