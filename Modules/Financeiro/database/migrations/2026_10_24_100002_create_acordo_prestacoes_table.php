<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acordo_prestacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('acordo_id')->constrained('acordos_divida')->restrictOnDelete();
            $table->unsignedSmallInteger('ordem');
            $table->date('data_vencimento');
            $table->unsignedBigInteger('valor');
            $table->timestamps();

            $table->unique(['acordo_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acordo_prestacoes');
    }
};
