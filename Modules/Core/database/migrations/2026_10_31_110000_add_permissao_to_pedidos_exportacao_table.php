<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_exportacao', function (Blueprint $table) {
            // Permissões (separadas por vírgula) que um destinatário tem de ter para receber o ficheiro; null = não se partilha.
            $table->string('permissao')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_exportacao', function (Blueprint $table) {
            $table->dropColumn('permissao');
        });
    }
};
