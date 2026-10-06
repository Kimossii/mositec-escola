<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('dominio')->unique();
            $table->integer('tipo')->default(0); // 0: Subdomínio, 1: Domínio personalizado
            $table->string('tipo_descricao')->default('Subdomínio');
            $table->boolean('is_principal')->default(false);
            $table->timestamps();
        });

        // Índice único parcial: no máximo um domínio principal por tenant.
        // O Blueprint não tem API para índices parciais; a sintaxe é comum a PostgreSQL e SQLite.
        DB::statement('CREATE UNIQUE INDEX domains_tenant_principal_unique ON domains (tenant_id) WHERE is_principal = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
