<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regras_cobranca', function (Blueprint $table) {
            $table->boolean('exigir_regularizacao_matricula')->default(true)->after('exigir_regularizacao_trancamento');
        });
    }

    public function down(): void
    {
        Schema::table('regras_cobranca', function (Blueprint $table) {
            $table->dropColumn('exigir_regularizacao_matricula');
        });
    }
};
