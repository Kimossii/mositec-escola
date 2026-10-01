<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estabelecimento_etapas_ensino', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('estabelecimento_id');
            $table->unsignedTinyInteger('etapa_ensino');
            $table->string('etapa_ensino_descricao');
            $table->timestamps();

            $table->unique(['estabelecimento_id', 'etapa_ensino']);

            // Uma etapa só pode apontar para o estabelecimento do seu próprio tenant (spec §8.1).
            $table->foreign(['tenant_id', 'estabelecimento_id'])
                ->references(['tenant_id', 'id'])
                ->on('estabelecimentos')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estabelecimento_etapas_ensino');
    }
};
