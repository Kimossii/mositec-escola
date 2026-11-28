<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Consumo mensal de emails opcionais por escola (quota da subscrição) e marcas de avisos já dados nesse mês. */
    public function up(): void
    {
        Schema::create('consumo_email_mensal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->char('mes', 7); // AAAA-MM, UTC
            $table->unsignedInteger('opcionais')->default(0);
            $table->boolean('aviso_enviado')->default(false);
            $table->boolean('bloqueio_avisado')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumo_email_mensal');
    }
};
