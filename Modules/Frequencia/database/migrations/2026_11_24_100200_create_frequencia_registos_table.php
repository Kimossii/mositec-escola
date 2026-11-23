<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frequencia_registos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('aula_id');
            $table->unsignedBigInteger('matricula_id');
            $table->unsignedTinyInteger('estado');
            $table->string('estado_descricao');
            $table->string('observacao', 500)->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'frequencia_registos_tenant_id_id_unique');
            $table->unique(['aula_id', 'matricula_id'], 'frequencia_registos_aula_matricula_unique');
            $table->index(['matricula_id', 'estado'], 'frequencia_registos_matricula_estado_index');

            $table->foreign(['tenant_id', 'aula_id'])->references(['tenant_id', 'id'])->on('frequencia_aulas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'matricula_id'])->references(['tenant_id', 'id'])->on('matriculas')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frequencia_registos');
    }
};
