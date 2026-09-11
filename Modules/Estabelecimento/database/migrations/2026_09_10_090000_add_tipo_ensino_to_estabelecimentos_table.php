<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->unsignedTinyInteger('tipo_ensino')->default(1)->after('tipo');
            $table->string('tipo_ensino_descricao')->default('Ensino Geral')->after('tipo_ensino');
        });
    }

    public function down(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->dropColumn(['tipo_ensino', 'tipo_ensino_descricao']);
        });
    }
};
