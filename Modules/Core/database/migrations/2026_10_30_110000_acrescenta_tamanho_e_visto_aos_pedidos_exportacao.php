<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_exportacao', function (Blueprint $table) {
            $table->unsignedBigInteger('tamanho')->default(0)->after('linhas'); // bytes do ficheiro final
            $table->timestamp('visto_em')->nullable()->after('concluido_em'); // quando o dono viu o resultado em «Os meus ficheiros»
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_exportacao', function (Blueprint $table) {
            $table->dropColumn(['tamanho', 'visto_em']);
        });
    }
};
