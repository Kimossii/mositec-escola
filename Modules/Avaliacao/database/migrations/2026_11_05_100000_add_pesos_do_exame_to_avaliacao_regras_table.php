<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_regras', function (Blueprint $table) {
            // Pesos do exame próprio (6.ª e 9.ª): MF = MFD × peso_mfd_exame + NE × peso_exame.
            $table->decimal('peso_mfd_exame', 5, 2)->default(40)->after('peso_mt3');
            $table->decimal('peso_exame', 5, 2)->default(60)->after('peso_mfd_exame');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras', fn (Blueprint $table) => $table->dropColumn(['peso_mfd_exame', 'peso_exame']));
    }
};
