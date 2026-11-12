<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acordo_propinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('acordo_id')->constrained('acordos_divida')->restrictOnDelete();
            $table->foreignId('propina_id')->constrained('propinas')->restrictOnDelete();
            $table->unsignedBigInteger('capital_inicial');
            $table->unsignedBigInteger('multa_inicial');
            $table->timestamps();

            $table->unique(['acordo_id', 'propina_id']);
            $table->index(['tenant_id', 'propina_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acordo_propinas');
    }
};
