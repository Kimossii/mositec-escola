<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Progressão do Pré-Escolar: automática (todos transitam) ou decidida criança a criança.
        Schema::table('avaliacao_regras_qualitativas', function (Blueprint $table) {
            $table->unsignedTinyInteger('progressao')->default(0)->after('escala_id');
            $table->string('progressao_descricao')->default('Automática')->after('progressao');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras_qualitativas', fn (Blueprint $table) => $table->dropColumn(['progressao', 'progressao_descricao']));
    }
};
