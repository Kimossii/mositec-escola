<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_regras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('ano_lectivo_id');
            $table->foreignId('nivel_academico_id')->constrained('niveis_academicos')->restrictOnDelete();
            $table->unsignedTinyInteger('escala_maxima');
            $table->decimal('nota_minima', 5, 2);
            $table->decimal('peso_mac', 5, 2)->default(50);
            $table->decimal('peso_pt', 5, 2)->default(50);
            // Pesos dos trimestres: nulos = trimestres com o mesmo peso.
            $table->decimal('peso_mt1', 5, 2)->nullable();
            $table->decimal('peso_mt2', 5, 2)->nullable();
            $table->decimal('peso_mt3', 5, 2)->nullable();
            $table->unsignedTinyInteger('casas_decimais')->default(2);
            $table->unsignedTinyInteger('modo_arredondamento');
            $table->string('modo_arredondamento_descricao');
            $table->boolean('reprova_por_aproveitamento')->default(true);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'ano_lectivo_id'])->references(['tenant_id', 'id'])->on('ano_lectivos')->restrictOnDelete();
            $table->unique(['tenant_id', 'ano_lectivo_id', 'nivel_academico_id'], 'avaliacao_regras_ano_nivel_unique');
            $table->unique(['tenant_id', 'id'], 'avaliacao_regras_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_regras');
    }
};
