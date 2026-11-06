<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_pauta_linhas', function (Blueprint $table) {
            $table->decimal('ner', 8, 4)->nullable()->after('mf');
            $table->decimal('mfr_exacta', 14, 10)->nullable()->after('ner');
            $table->decimal('mfr', 8, 4)->nullable()->after('mfr_exacta');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_pauta_linhas', fn (Blueprint $table) => $table->dropColumn(['ner', 'mfr_exacta', 'mfr']));
    }
};
