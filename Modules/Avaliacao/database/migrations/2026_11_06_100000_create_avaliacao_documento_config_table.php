<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_documento_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // Linhas do cabeçalho oficial entre a Administração Municipal e o nome da escola (ex.: «Direcção Municipal da Educação»).
            $table->text('linhas_extra')->nullable();
            $table->string('lema')->nullable();
            // «Visto» de uma entidade externa (ex.: Directora Municipal), impresso nas pautas.
            $table->string('visto_cargo')->nullable();
            $table->string('visto_nome')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_documento_config');
    }
};
