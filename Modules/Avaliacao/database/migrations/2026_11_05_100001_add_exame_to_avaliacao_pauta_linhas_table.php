<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_pauta_linhas', function (Blueprint $table) {
            $table->decimal('ne', 8, 4)->nullable()->after('mt3');
            $table->decimal('mf_exacta', 14, 10)->nullable()->after('mfd');
            $table->decimal('mf', 8, 4)->nullable()->after('mf_exacta');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_pauta_linhas', fn (Blueprint $table) => $table->dropColumn(['ne', 'mf_exacta', 'mf']));
    }
};
