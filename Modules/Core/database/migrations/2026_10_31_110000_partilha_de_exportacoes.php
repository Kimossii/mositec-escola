<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_exportacao', function (Blueprint $table) {
            // Permissões (separadas por vírgula) que um destinatário tem de ter para receber o ficheiro; null = não se partilha.
            $table->string('permissao')->nullable()->after('nome');
        });

        Schema::create('pedidos_exportacao_partilhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('pedido_exportacao_id')->constrained('pedidos_exportacao')->cascadeOnDelete();
            $table->foreignId('destinatario_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('partilhado_por')->constrained('users')->cascadeOnDelete();
            $table->timestamp('visto_em')->nullable();
            $table->timestamps();

            $table->unique(['pedido_exportacao_id', 'destinatario_id']);
            $table->index(['tenant_id', 'destinatario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_exportacao_partilhas');
        Schema::table('pedidos_exportacao', function (Blueprint $table) {
            $table->dropColumn('permissao');
        });
    }
};
