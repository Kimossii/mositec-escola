<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma turma passa a ter até duas pautas finais: a da época normal (1.ª fase) e a do recurso (2.ª fase).
        Schema::table('avaliacao_pautas', function (Blueprint $table) {
            $table->unsignedTinyInteger('fase')->default(1)->after('turma_id');
            $table->string('fase_descricao')->default('1.ª fase')->after('fase');
            $table->dropUnique(['turma_id']);
            $table->unique(['turma_id', 'fase']);
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_pautas', function (Blueprint $table) {
            $table->dropUnique(['turma_id', 'fase']);
            $table->unique('turma_id');
            $table->dropColumn(['fase', 'fase_descricao']);
        });
    }
};
