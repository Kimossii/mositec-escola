<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A regra deixa de ser «de uma classe»: tem nome e opções próprias, e as classes ligam-se a ela (avaliacao_regra_niveis).
        Schema::table('avaliacao_regras', function (Blueprint $table) {
            $table->string('nome')->default('')->after('ano_lectivo_id');
            $table->boolean('com_exame')->default(false)->after('reprova_por_aproveitamento');
            $table->boolean('com_recurso')->default(false)->after('com_exame');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras', fn (Blueprint $table) => $table->dropColumn(['nome', 'com_exame', 'com_recurso']));
    }
};
