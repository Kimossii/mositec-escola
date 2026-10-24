<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('moeda_cotada', 3);
            $table->string('moeda_base', 3)->default('USD');
            $table->date('data');
            $table->unsignedBigInteger('taxa');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'moeda_cotada', 'moeda_base', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios');
    }
};
