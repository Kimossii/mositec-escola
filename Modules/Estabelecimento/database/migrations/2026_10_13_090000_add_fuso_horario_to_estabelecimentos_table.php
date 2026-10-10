<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            // O "hoje" de negócio da escola. A zona da aplicação (UTC) e os timestamps guardados não mudam.
            $table->string('fuso_horario', 64)->default('Africa/Luanda')->after('provincia');
        });
    }

    public function down(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->dropColumn('fuso_horario');
        });
    }
};
