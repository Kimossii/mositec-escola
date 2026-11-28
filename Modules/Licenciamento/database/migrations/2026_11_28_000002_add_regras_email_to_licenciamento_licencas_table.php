<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Regras de email da subscrição: quota mensal de opcionais (nulo = sem limite), % de aviso e se bloqueia ao exceder.
        Schema::table('licenciamento_licencas', function (Blueprint $table) {
            $table->unsignedInteger('limite_emails_mes')->nullable();
            $table->unsignedTinyInteger('emails_aviso_pct')->default(80);
            $table->boolean('emails_bloqueia_ao_exceder')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('licenciamento_licencas', function (Blueprint $table) {
            $table->dropColumn(['limite_emails_mes', 'emails_aviso_pct', 'emails_bloqueia_ao_exceder']);
        });
    }
};
