<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_regras', function (Blueprint $table) {
            $table->unsignedTinyInteger('tratamento_negativas')->default(0)->after('modo_arredondamento_descricao');
            $table->string('tratamento_negativas_descricao')->default('Reprova se tiver negativa')->after('tratamento_negativas');
        });

        // Ligado = reprova. Desligado = passava sem aviso: passa a «transita, com alerta» (a negativa deixa de ficar escondida).
        DB::table('avaliacao_regras')->where('reprova_por_aproveitamento', true)->update(['tratamento_negativas' => 0, 'tratamento_negativas_descricao' => 'Reprova se tiver negativa']);
        DB::table('avaliacao_regras')->where('reprova_por_aproveitamento', false)->update(['tratamento_negativas' => 1, 'tratamento_negativas_descricao' => 'Transita, com alerta das negativas']);

        Schema::table('avaliacao_regras', fn (Blueprint $table) => $table->dropColumn('reprova_por_aproveitamento'));
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras', fn (Blueprint $table) => $table->boolean('reprova_por_aproveitamento')->default(true)->after('modo_arredondamento_descricao'));
        DB::table('avaliacao_regras')->where('tratamento_negativas', 0)->update(['reprova_por_aproveitamento' => true]);
        DB::table('avaliacao_regras')->where('tratamento_negativas', '!=', 0)->update(['reprova_por_aproveitamento' => false]);

        Schema::table('avaliacao_regras', fn (Blueprint $table) => $table->dropColumn(['tratamento_negativas', 'tratamento_negativas_descricao']));
    }
};
