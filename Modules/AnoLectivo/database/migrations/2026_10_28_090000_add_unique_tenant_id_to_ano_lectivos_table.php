<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Alvo das chaves estrangeiras compostas (tenant_id, id) de outros módulos (Docente). */
    public function up(): void
    {
        Schema::table('ano_lectivos', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'ano_lectivos_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ano_lectivos', function (Blueprint $table) {
            $table->dropUnique('ano_lectivos_tenant_id_id_unique');
        });
    }
};
