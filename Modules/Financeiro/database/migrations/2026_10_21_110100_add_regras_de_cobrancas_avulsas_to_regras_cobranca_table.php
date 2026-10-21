<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regras_cobranca', function (Blueprint $table) {
            $table->boolean('exigir_regularizacao_servicos')->default(true)->after('exigir_regularizacao_matricula');
            $table->boolean('gerar_obrigatorios_ao_matricular')->default(true)->after('exigir_regularizacao_servicos');
        });
    }

    public function down(): void
    {
        Schema::table('regras_cobranca', function (Blueprint $table) {
            $table->dropColumn(['exigir_regularizacao_servicos', 'gerar_obrigatorios_ao_matricular']);
        });
    }
};
