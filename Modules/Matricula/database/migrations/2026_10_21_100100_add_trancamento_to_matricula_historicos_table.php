<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matricula_historicos', function (Blueprint $table) {
            $table->date('data_efeito')->nullable()->after('utilizador_id');
            $table->text('motivo')->nullable()->after('data_efeito');
            $table->json('detalhe')->nullable()->after('motivo');
        });
    }

    public function down(): void
    {
        Schema::table('matricula_historicos', function (Blueprint $table) {
            $table->dropColumn(['data_efeito', 'motivo', 'detalhe']);
        });
    }
};
