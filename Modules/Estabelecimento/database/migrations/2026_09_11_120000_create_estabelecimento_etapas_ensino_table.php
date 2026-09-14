<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estabelecimento_etapas_ensino', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->constrained('estabelecimentos')->cascadeOnDelete();
            $table->unsignedTinyInteger('etapa_ensino');
            $table->string('etapa_ensino_descricao');
            $table->timestamps();

            $table->unique(['estabelecimento_id', 'etapa_ensino']);
        });

        $this->backfillEtapasExistentes();
    }

    public function down(): void
    {
        Schema::dropIfExists('estabelecimento_etapas_ensino');
    }

    private function backfillEtapasExistentes(): void
    {
        $agora = now();

        DB::table('estabelecimentos')->select('id', 'tipo_ensino')->get()->each(function ($estabelecimento) use ($agora) {
            if ((int) $estabelecimento->tipo_ensino === TipoEnsinoEnum::UNIVERSITARIO->value) {
                $etapas = [EtapaEnsinoEnum::SUPERIOR];
            } else {
                $etapas = DB::table('niveis_academicos')
                    ->where('estabelecimento_id', $estabelecimento->id)
                    ->distinct()
                    ->pluck('etapa_ensino')
                    ->map(fn ($valor) => EtapaEnsinoEnum::from((int) $valor))
                    ->all();
            }

            foreach ($etapas as $etapa) {
                DB::table('estabelecimento_etapas_ensino')->insert([
                    'estabelecimento_id' => $estabelecimento->id,
                    'etapa_ensino' => $etapa->value,
                    'etapa_ensino_descricao' => $etapa->label(),
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }
        });
    }
};
