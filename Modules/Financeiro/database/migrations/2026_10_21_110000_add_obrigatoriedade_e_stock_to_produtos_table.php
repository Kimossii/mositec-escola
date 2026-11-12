<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->boolean('obrigatorio')->default(false)->after('preco');
            $table->unsignedTinyInteger('momento_obrigatorio')->nullable()->after('obrigatorio');
            $table->string('momento_obrigatorio_descricao')->nullable()->after('momento_obrigatorio');
            $table->boolean('apenas_alunos_novos')->default(false)->after('momento_obrigatorio_descricao');
            $table->boolean('controla_stock')->default(false)->after('apenas_alunos_novos');
            $table->unsignedInteger('stock_quantidade')->default(0)->after('controla_stock');
            $table->boolean('tem_variantes')->default(false)->after('stock_quantidade');
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn(['obrigatorio', 'momento_obrigatorio', 'momento_obrigatorio_descricao', 'apenas_alunos_novos', 'controla_stock', 'stock_quantidade', 'tem_variantes']);
        });
    }
};
