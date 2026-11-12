<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};
