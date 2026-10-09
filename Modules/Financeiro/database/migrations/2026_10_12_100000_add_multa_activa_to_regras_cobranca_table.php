<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regras_cobranca', function (Blueprint $table) {
            $table->boolean('multa_activa')->default(false)->after('desconto_maximo_negociacao');
        });
    }

    public function down(): void
    {
        Schema::table('regras_cobranca', function (Blueprint $table) {
            $table->dropColumn('multa_activa');
        });
    }
};
