<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Licenças emitidas: registo comercial do emissor (global, sem PertenceAoTenant). Guarda um SNAPSHOT dos direitos
        // (editar o plano depois não a altera). `id` é o `jti` do token. `escola` é o código da escola e `tenant_local_id` o id
        // local quando a escola é desta instalação: ambos sem chave estrangeira (o módulo é independente).
        Schema::create('licenciamento_licencas', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->unsignedBigInteger('plano_id')->nullable()->index();
            $table->string('escola')->nullable()->index();
            $table->unsignedBigInteger('tenant_local_id')->nullable();
            $table->string('instalacao')->nullable();
            $table->boolean('ligacao_obrigatoria')->default(true);
            $table->unsignedTinyInteger('modalidade');
            $table->string('modalidade_descricao');
            $table->unsignedTinyInteger('estado_comercial');
            $table->string('estado_comercial_descricao');
            $table->unsignedInteger('limite_alunos')->nullable();
            $table->unsignedInteger('limite_utilizadores')->nullable();
            $table->unsignedInteger('limite_estabelecimentos')->nullable();
            $table->unsignedSmallInteger('tolerancia_dias');
            $table->unsignedSmallInteger('aviso_dias');
            $table->json('modulos');
            $table->timestamp('emitida_em');
            $table->timestamp('inicio');
            $table->timestamp('expira_em');
            $table->text('token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenciamento_licencas');
    }
};
