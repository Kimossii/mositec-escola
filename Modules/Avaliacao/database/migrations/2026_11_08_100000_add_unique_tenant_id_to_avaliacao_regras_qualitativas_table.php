<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_regras_qualitativas', fn (Blueprint $table) => $table->unique(['tenant_id', 'id'], 'avaliacao_regras_qualitativas_tenant_id_id_unique'));
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras_qualitativas', fn (Blueprint $table) => $table->dropUnique('avaliacao_regras_qualitativas_tenant_id_id_unique'));
    }
};
