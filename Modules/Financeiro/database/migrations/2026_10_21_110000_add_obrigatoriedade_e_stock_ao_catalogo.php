<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['produtos', 'servicos'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) use ($tabela) {
                $table->boolean('obrigatorio')->default(false)->after('preco');
                $table->unsignedTinyInteger('momento_obrigatorio')->nullable()->after('obrigatorio');
                $table->string('momento_obrigatorio_descricao')->nullable()->after('momento_obrigatorio');
                $table->boolean('apenas_alunos_novos')->default(false)->after('momento_obrigatorio_descricao');

                if ($tabela === 'produtos') {
                    $table->boolean('controla_stock')->default(false)->after('apenas_alunos_novos');
                    $table->unsignedInteger('stock_quantidade')->default(0)->after('controla_stock');
                    $table->boolean('tem_variantes')->default(false)->after('stock_quantidade');
                }
            });
        }

        Schema::create('produto_variantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->cascadeOnDelete();
            $table->string('nome', 100); // ex.: tamanho P, M, G, 38
            $table->unsignedInteger('stock_quantidade')->default(0);
            $table->timestamps();

            $table->unique(['produto_id', 'nome']);
        });

        // A quem se aplica um item obrigatório: níveis e/ou cursos; sem linhas = a todos.
        Schema::create('catalogo_alvos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('item_tipo', 20); // produto | servico
            $table->unsignedBigInteger('item_id');
            $table->foreignId('nivel_academico_id')->nullable()->constrained('niveis_academicos')->restrictOnDelete();
            $table->foreignId('curso_id')->nullable()->constrained('cursos')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'item_tipo', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_alvos');
        Schema::dropIfExists('produto_variantes');

        foreach (['produtos', 'servicos'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) use ($tabela) {
                $colunas = ['obrigatorio', 'momento_obrigatorio', 'momento_obrigatorio_descricao', 'apenas_alunos_novos'];
                if ($tabela === 'produtos') {
                    array_push($colunas, 'controla_stock', 'stock_quantidade', 'tem_variantes');
                }
                $table->dropColumn($colunas);
            });
        }
    }
};
