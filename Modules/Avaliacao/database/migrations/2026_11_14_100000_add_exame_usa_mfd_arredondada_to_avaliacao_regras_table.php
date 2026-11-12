<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opção da regra: a nota com exame (e a do recurso) parte da nota final como aparece na pauta (arredondada) ou do valor exacto.
        Schema::table('avaliacao_regras', function (Blueprint $table) {
            $table->boolean('exame_usa_mfd_arredondada')->default(true)->after('com_recurso');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras', fn (Blueprint $table) => $table->dropColumn('exame_usa_mfd_arredondada'));
    }
};
