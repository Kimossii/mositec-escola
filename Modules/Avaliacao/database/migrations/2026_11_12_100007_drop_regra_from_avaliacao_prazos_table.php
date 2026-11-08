<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_prazos', function (Blueprint $table) {
            $table->dropUnique('avaliacao_prazos_regra_periodo_tipo_unique');
            $table->dropUnique('avaliacao_prazos_regra_qualitativa_periodo_tipo_unique');
            $table->dropForeign(['tenant_id', 'regra_id']);
            $table->dropForeign(['tenant_id', 'regra_qualitativa_id']);
            $table->dropColumn(['regra_id', 'regra_qualitativa_id']);
            $table->unique(['tenant_id', 'periodo_id', 'tipo'], 'avaliacao_prazos_periodo_tipo_unique');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_prazos', function (Blueprint $table) {
            $table->dropUnique('avaliacao_prazos_periodo_tipo_unique');
            $table->unsignedBigInteger('regra_id')->nullable();
            $table->unsignedBigInteger('regra_qualitativa_id')->nullable();
        });

        Schema::table('avaliacao_prazos', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'regra_id'])->references(['tenant_id', 'id'])->on('avaliacao_regras')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'regra_qualitativa_id'])->references(['tenant_id', 'id'])->on('avaliacao_regras_qualitativas')->cascadeOnDelete();
            $table->unique(['regra_id', 'periodo_id', 'tipo'], 'avaliacao_prazos_regra_periodo_tipo_unique');
            $table->unique(['regra_qualitativa_id', 'periodo_id', 'tipo'], 'avaliacao_prazos_regra_qualitativa_periodo_tipo_unique');
        });
    }
};
