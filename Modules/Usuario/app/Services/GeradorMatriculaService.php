<?php

namespace Modules\Usuario\Services;

use Illuminate\Support\Facades\DB;
use Modules\Usuario\Models\MatriculaSequencia;

class GeradorMatriculaService
{
    public function gerar(): string
    {
        return DB::transaction(function () {
            $ano = now()->year;

            DB::table('matricula_sequencias')->upsert(
                [['ano' => $ano, 'ultimo_numero' => 0, 'created_at' => now(), 'updated_at' => now()]],
                ['ano'],
                ['updated_at']
            );

            $sequencia = MatriculaSequencia::where('ano', $ano)->lockForUpdate()->first();
            $sequencia->increment('ultimo_numero');

            return sprintf('%d-%04d', $ano, $sequencia->ultimo_numero);
        });
    }
}
