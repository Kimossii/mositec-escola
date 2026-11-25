<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Alvo das chaves estrangeiras compostas (tenant_id, id) de outros módulos (Frequência). */
    public function up(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'matriculas_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->dropUnique('matriculas_tenant_id_id_unique');
        });
    }
};
