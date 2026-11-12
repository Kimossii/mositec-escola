<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A 7.ª–9.ª classes (SEC_7, SEC_8, SEC_9) são Ensino Primário (3), onde não há cursos; só a 10.ª em diante é Secundário. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('niveis_academicos')->whereIn('codigo', ['SEC_7', 'SEC_8', 'SEC_9'])->where('etapa_ensino', 4)
            ->update(['etapa_ensino' => 3, 'etapa_ensino_descricao' => 'Ensino Primário']);
    }

    public function down(): void
    {
        DB::table('niveis_academicos')->whereIn('codigo', ['SEC_7', 'SEC_8', 'SEC_9'])->where('etapa_ensino', 3)
            ->update(['etapa_ensino' => 4, 'etapa_ensino_descricao' => 'Ensino Secundário']);
    }
};
