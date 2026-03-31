<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
             $table->string('name');
            $table->string('email')->unique();
            $table->foreignId('dados_pessoa_id')->nullable()->constrained('dados_pessoas')->onDelete('set null');
            $table->integer('estado')->default(1); // 0 = inativo, 1 = ativo
            $table->foreignId('criado_por')->nullable()->constrained('usuarios')->onDelete('set null');
            $table->foreignId('editado_por')->nullable()->constrained('usuarios')->onDelete('set null');
            $table->string('password');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
