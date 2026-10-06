<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;

return new class extends Migration
{
    public function up(): void
    {
        // Colunas nascem nullable para permitir o backfill abaixo — em
        // ambientes já com níveis académicos reais (ex.: dev/staging),
        // adicionar directamente como NOT NULL falharia. Só depois do
        // backfill é que a constraint é reforçada.
        Schema::table('niveis_academicos', function (Blueprint $table) {
            $table->unsignedTinyInteger('etapa_ensino')->nullable()->after('nome');
            $table->string('etapa_ensino_descricao')->nullable()->after('etapa_ensino');
        });

        DB::table('niveis_academicos')->whereNull('etapa_ensino')->update([
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO->value,
            'etapa_ensino_descricao' => EtapaEnsinoEnum::SECUNDARIO->label(),
        ]);

        Schema::table('niveis_academicos', function (Blueprint $table) {
            $table->unsignedTinyInteger('etapa_ensino')->nullable(false)->change();
            $table->string('etapa_ensino_descricao')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('niveis_academicos', function (Blueprint $table) {
            $table->dropColumn(['etapa_ensino', 'etapa_ensino_descricao']);
        });
    }
};
