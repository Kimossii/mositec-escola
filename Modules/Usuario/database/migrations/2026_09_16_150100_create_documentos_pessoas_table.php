<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_pessoas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dados_pessoa_id')->constrained('dados_pessoas')->cascadeOnDelete();
            $table->foreignId('tipo_documento_id')->constrained('tipos_documentos')->restrictOnDelete();
            $table->string('numero_documento')->nullable();
            $table->date('data_emissao')->nullable();
            $table->date('data_validade')->nullable();
            $table->string('nome_original');
            $table->string('caminho');
            $table->string('mime_type');
            $table->unsignedInteger('tamanho');
            $table->text('observacoes')->nullable();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['dados_pessoa_id', 'tipo_documento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_pessoas');
    }
};
