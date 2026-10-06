<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration incremental (as originais não se editam: há bases com dados): o motivo, opcional, do
 * encerramento e a data da última reactivação (antes só existia `updated_at`). Só acrescenta colunas
 * nulas; os dados existentes ficam intactos.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('motivo_encerramento', 255)->nullable()->after('encerrado_em');
            $table->timestamp('reactivado_em')->nullable()->after('motivo_encerramento');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['motivo_encerramento', 'reactivado_em']);
        });
    }
};
