<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A migração original definia `->default(null)` numa coluna que já era
     * NOT NULL — o Postgres nunca aplicou esse default (confirmado: nenhuma
     * linha tem `permitido` nulo, `information_schema` não mostra default
     * nenhum). Só o comentário do código sugeria um terceiro estado "Herda"
     * guardável, que nunca chegou a existir na prática. `permitido` é
     * sempre `true` (Concedido) ou `false` (Negado); a ausência de uma
     * linha para (utilizador, módulo, acção) é que significa "sem
     * override" — nunca um valor nulo guardado.
     */
    public function up(): void
    {
        Schema::table('user_permissoes', function (Blueprint $table) {
            $table->boolean('permitido')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_permissoes', function (Blueprint $table) {
            $table->boolean('permitido')->default(null)->change();
        });
    }
};
