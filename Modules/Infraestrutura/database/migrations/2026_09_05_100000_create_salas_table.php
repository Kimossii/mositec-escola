<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->nullable()->constrained('estabelecimentos')->nullOnDelete();
            $table->string('codigo', 20);
            $table->string('nome');
            $table->unsignedTinyInteger('tipo'); // 0 sala_aula | 1 laboratorio | 2 biblioteca | 3 auditorio | 4 ginasio | 5 sala_professores | 6 gabinete_administrativo | 7 outro
            $table->string('tipo_descricao');
            $table->unsignedSmallInteger('capacidade')->nullable();
            $table->string('localizacao')->nullable();
            $table->text('observacoes')->nullable();
            $table->unsignedTinyInteger('estado')->default(0); // 0 ativa | 1 manutencao | 2 inativa
            $table->string('estado_descricao')->default('Ativa');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['estabelecimento_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salas');
    }
};
