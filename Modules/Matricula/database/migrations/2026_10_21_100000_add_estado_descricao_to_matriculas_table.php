<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROTULOS = [1 => 'Pendente', 2 => 'Activa', 3 => 'Cancelada', 4 => 'Concluída', 5 => 'Transferida', 6 => 'Trancada'];

    public function up(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->string('estado_descricao', 30)->nullable()->after('estado');
        });

        foreach (self::ROTULOS as $estado => $rotulo) {
            DB::table('matriculas')->where('estado', $estado)->update(['estado_descricao' => $rotulo]);
        }
    }

    public function down(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->dropColumn('estado_descricao');
        });
    }
};
