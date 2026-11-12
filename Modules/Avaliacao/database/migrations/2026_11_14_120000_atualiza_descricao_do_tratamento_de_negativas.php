<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // «Transita, com alerta» passou a «Transita, com as negativas tratadas»: a negativa tem de ser tratada, não só avisada.
        DB::table('avaliacao_regras')->where('tratamento_negativas', 1)->update(['tratamento_negativas_descricao' => 'Transita, com as negativas tratadas']);
    }

    public function down(): void
    {
        DB::table('avaliacao_regras')->where('tratamento_negativas', 1)->update(['tratamento_negativas_descricao' => 'Transita, com alerta das negativas']);
    }
};
