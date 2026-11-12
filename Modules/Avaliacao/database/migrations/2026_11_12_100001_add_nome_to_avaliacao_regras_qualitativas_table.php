<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A regra qualitativa também tem nome. Pode existir antes de a escola escolher a escala.
        Schema::table('avaliacao_regras_qualitativas', function (Blueprint $table) {
            $table->string('nome')->default('')->after('ano_lectivo_id');
            $table->unsignedBigInteger('escala_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras_qualitativas', function (Blueprint $table) {
            $table->dropColumn('nome');
            $table->unsignedBigInteger('escala_id')->nullable(false)->change();
        });
    }
};
