<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servicos', function (Blueprint $table) {
            $table->boolean('obrigatorio')->default(false)->after('preco');
            $table->unsignedTinyInteger('momento_obrigatorio')->nullable()->after('obrigatorio');
            $table->string('momento_obrigatorio_descricao')->nullable()->after('momento_obrigatorio');
            $table->boolean('apenas_alunos_novos')->default(false)->after('momento_obrigatorio_descricao');
        });
    }

    public function down(): void
    {
        Schema::table('servicos', function (Blueprint $table) {
            $table->dropColumn(['obrigatorio', 'momento_obrigatorio', 'momento_obrigatorio_descricao', 'apenas_alunos_novos']);
        });
    }
};
