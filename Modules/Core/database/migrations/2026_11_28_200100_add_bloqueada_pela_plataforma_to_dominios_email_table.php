<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** A Plataforma pode bloquear o domínio próprio de uma escola (abuso): a escola deixa de o poder verificar, activar ou remover. */
    public function up(): void
    {
        Schema::table('dominios_email', function (Blueprint $table) {
            $table->boolean('bloqueada_pela_plataforma')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dominios_email', function (Blueprint $table) {
            $table->dropColumn('bloqueada_pela_plataforma');
        });
    }
};
