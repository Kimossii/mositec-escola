<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cobrança de produtos e serviços a uma matrícula (nunca a propinas). Estado e valor pago só os escreve RecalcularCobranca.
        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->restrictOnDelete();
            $table->string('moeda', 3);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('valor_pago')->default(0);
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Em Aberto');
            $table->unsignedTinyInteger('origem')->default(1); // 1 manual, 2 obrigatório
            $table->string('origem_descricao')->default('Manual');
            $table->date('data_limite')->nullable();
            $table->text('observacao')->nullable();
            $table->text('cancelamento_motivo')->nullable();
            $table->foreignId('cancelamento_utilizador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelamento_data')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'matricula_id']);
            $table->index(['tenant_id', 'estado']);
        });

        // Linhas da cobrança: guardam uma CÓPIA do nome, código e preço no momento da venda.
        Schema::create('cobranca_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('cobranca_id')->constrained('cobrancas')->restrictOnDelete();
            $table->string('tipo', 20); // produto | servico
            $table->foreignId('produto_id')->nullable()->constrained('produtos')->restrictOnDelete();
            $table->foreignId('servico_id')->nullable()->constrained('servicos')->restrictOnDelete();
            $table->foreignId('variante_id')->nullable()->constrained('produto_variantes')->restrictOnDelete();
            $table->string('nome');
            $table->string('codigo', 50)->nullable();
            $table->string('variante_nome', 100)->nullable();
            $table->unsignedBigInteger('preco_unitario');
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('total');
            $table->unsignedTinyInteger('stock_abatido')->default(0); // 1 se esta linha abateu stock (para repor ao cancelar)
            $table->timestamps();

            $table->index(['tenant_id', 'cobranca_id']);
        });

        // Dinheiro de um pagamento aplicado a uma cobrança (só se cria; anula-se o pagamento).
        Schema::create('pagamento_cobrancas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('pagamento_id')->constrained('pagamentos')->restrictOnDelete();
            $table->foreignId('cobranca_id')->constrained('cobrancas')->restrictOnDelete();
            $table->unsignedBigInteger('valor');
            $table->timestamp('created_at')->nullable();

            $table->unique(['pagamento_id', 'cobranca_id']);
            $table->index(['tenant_id', 'cobranca_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamento_cobrancas');
        Schema::dropIfExists('cobranca_itens');
        Schema::dropIfExists('cobrancas');
    }
};
