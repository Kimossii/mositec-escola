<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quem pode ir ao recurso: máximo de disciplinas negativas (acima dele, reprovação directa).
        Schema::table('avaliacao_regras', function (Blueprint $table) {
            $table->unsignedTinyInteger('recurso_max_disciplinas')->nullable()->after('peso_exame');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras', fn (Blueprint $table) => $table->dropColumn('recurso_max_disciplinas'));
    }
};
