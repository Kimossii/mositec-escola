<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_escala_niveis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('escala_id');
            $table->string('codigo', 20);
            $table->string('designacao');
            $table->unsignedSmallInteger('ordem');
            $table->timestamps();

            $table->foreign(['tenant_id', 'escala_id'])->references(['tenant_id', 'id'])->on('avaliacao_escalas')->cascadeOnDelete();
            $table->unique(['escala_id', 'codigo']);
            $table->unique(['escala_id', 'ordem']);
            $table->unique(['tenant_id', 'id'], 'avaliacao_escala_niveis_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_escala_niveis');
    }
};
