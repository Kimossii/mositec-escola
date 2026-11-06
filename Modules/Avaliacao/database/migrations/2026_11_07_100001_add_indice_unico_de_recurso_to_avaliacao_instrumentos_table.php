<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Um único exame de recurso (tipo 3) por turma e disciplina; a Action valida primeiro.
            DB::statement('CREATE UNIQUE INDEX avaliacao_instrumentos_um_recurso_unique ON avaliacao_instrumentos (turma_id, disciplina_id) WHERE tipo = 3');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS avaliacao_instrumentos_um_recurso_unique');
        }
    }
};
