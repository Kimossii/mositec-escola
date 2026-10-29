<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE atribuicoes_docentes ADD CONSTRAINT atribuicoes_docentes_datas_check CHECK (data_fim IS NULL OR data_fim >= data_inicio)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE atribuicoes_docentes DROP CONSTRAINT IF EXISTS atribuicoes_docentes_datas_check');
    }
};
