<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('modulos', function (Blueprint $table) {
            $table->id();
            $table->integer('nome')->default(0); // 0: usuario, 1: autorizacao, 2: anoletivo, 3: licenca, 4: aluno, 5: professor, 6: turmas, 7: matricula,
            // 8: disciplina, 9: nota, 10: frequencia, 11: horario, 12: materialditatico, 13: financeiro, 14: relatorio, 15: documento, 16: sincronizacao, 17: configuracao,
            // 18: comunicacao, 19: auditoria,
            $table->string('descricao')->nullable();
            $table->integer('estado')->default(1); // 0: inativo, 1: ativo
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modulos');
    }
};
