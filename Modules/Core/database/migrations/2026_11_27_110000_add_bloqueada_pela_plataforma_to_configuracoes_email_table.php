<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** A Plataforma pode forçar o regresso à central e impedir a escola de reactivar o servidor próprio até levantar o bloqueio. */
    public function up(): void
    {
        Schema::table('configuracoes_email', function (Blueprint $table) {
            $table->boolean('bloqueada_pela_plataforma')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes_email', function (Blueprint $table) {
            $table->dropColumn('bloqueada_pela_plataforma');
        });
    }
};
