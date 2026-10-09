<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios_plataforma', function (Blueprint $table) {
            $table->id();
            $table->string('moeda_cotada', 3);
            $table->string('moeda_base', 3)->default('USD');
            $table->date('data');
            $table->unsignedBigInteger('taxa');
            $table->string('fonte', 20)->default('manual');
            $table->timestamps();

            $table->unique(['moeda_cotada', 'moeda_base', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_plataforma');
    }
};
