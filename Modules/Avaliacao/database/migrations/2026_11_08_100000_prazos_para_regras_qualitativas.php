<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_regras_qualitativas', fn (Blueprint $table) => $table->unique(['tenant_id', 'id'], 'avaliacao_regras_qualitativas_tenant_id_id_unique'));

        // Um prazo passa a poder pertencer a uma regra numérica OU a uma regra qualitativa (Creche e Pré-Escolar).
        Schema::table('avaliacao_prazos', function (Blueprint $table) {
            $table->unsignedBigInteger('regra_id')->nullable()->change();
            $table->unsignedBigInteger('regra_qualitativa_id')->nullable()->after('regra_id');
        });

        Schema::table('avaliacao_prazos', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'regra_qualitativa_id'])->references(['tenant_id', 'id'])->on('avaliacao_regras_qualitativas')->cascadeOnDelete();
            $table->unique(['regra_qualitativa_id', 'periodo_id', 'tipo'], 'avaliacao_prazos_regra_qualitativa_periodo_tipo_unique');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_prazos', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'regra_qualitativa_id']);
            $table->dropUnique('avaliacao_prazos_regra_qualitativa_periodo_tipo_unique');
            $table->dropColumn('regra_qualitativa_id');
        });

        Schema::table('avaliacao_regras_qualitativas', fn (Blueprint $table) => $table->dropUnique('avaliacao_regras_qualitativas_tenant_id_id_unique'));
    }
};
