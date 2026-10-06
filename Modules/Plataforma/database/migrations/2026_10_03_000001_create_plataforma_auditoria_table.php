<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Só acrescenta: sem updated_at. O código do tenant é texto, sem chave estrangeira,
        // para o registo sobreviver ao encerramento (e a qualquer remoção) da escola.
        Schema::create('plataforma_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('accao');
            $table->string('codigo_tenant', 20)->nullable()->index();
            $table->json('detalhe')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plataforma_auditoria');
    }
};
