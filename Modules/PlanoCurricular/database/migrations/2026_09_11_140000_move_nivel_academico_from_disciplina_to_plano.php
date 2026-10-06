<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planos_curriculares', function (Blueprint $table) {
            $table->foreignId('curso_id')->nullable()->change();
            $table->foreignId('nivel_academico_id')->after('curso_id')
                ->constrained('niveis_academicos')->restrictOnDelete();
        });

        Schema::table('plano_curricular_disciplinas', function (Blueprint $table) {
            $table->dropUnique('plano_disciplina_nivel_unique');
            $table->dropForeign(['nivel_academico_id']);
            $table->dropColumn('nivel_academico_id');
            $table->unique(['plano_curricular_id', 'disciplina_id']);
        });
    }

    public function down(): void
    {
        Schema::table('plano_curricular_disciplinas', function (Blueprint $table) {
            $table->dropUnique(['plano_curricular_id', 'disciplina_id']);
            $table->foreignId('nivel_academico_id')->nullable()
                ->constrained('niveis_academicos')->restrictOnDelete();
            $table->unique(['plano_curricular_id', 'disciplina_id', 'nivel_academico_id'], 'plano_disciplina_nivel_unique');
        });

        Schema::table('planos_curriculares', function (Blueprint $table) {
            $table->dropForeign(['nivel_academico_id']);
            $table->dropColumn('nivel_academico_id');
            $table->foreignId('curso_id')->nullable(false)->change();
        });
    }
};
