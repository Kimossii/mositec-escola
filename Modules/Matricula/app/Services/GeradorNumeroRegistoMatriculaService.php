<?php

namespace Modules\Matricula\Services;

use Illuminate\Support\Facades\DB;
use Modules\Matricula\Models\MatriculaRegistoSequencia;

class GeradorNumeroRegistoMatriculaService
{
    public function gerar(): string
    {
        return DB::transaction(function () {
            $ano = now()->year;

            DB::table('matricula_registo_sequencias')->upsert(
                [['ano' => $ano, 'ultimo_numero' => 0, 'created_at' => now(), 'updated_at' => now()]],
                ['ano'],
                ['updated_at']
            );

            $sequencia = MatriculaRegistoSequencia::where('ano', $ano)->lockForUpdate()->first();
            $sequencia->increment('ultimo_numero');

            return sprintf('%d-%04d', $ano, $sequencia->ultimo_numero);
        });
    }
}
