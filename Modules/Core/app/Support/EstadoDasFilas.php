<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Estado das filas `default` e `pesado` lido de `jobs` e `failed_jobs` (tabelas de infra-estrutura, sem tenant).
 * Partilhado por `mosi:filas:estado` e pelo painel da Plataforma. Só leitura.
 */
class EstadoDasFilas
{
    /** Minutos com jobs à espera antes de dar a fila como parada. */
    public const MINUTOS_PARA_ALERTA = 5;

    /** @return array{modo: string, filas: list<array{nome: string, em_espera: int, minutos: int, parada: bool}>, falhados: int} */
    public function obter(): array
    {
        $modo = (string) config('queue.default');

        // Só a fila na BD tem o que vigiar; em `sync` os jobs correm no pedido e outros drivers têm o seu serviço.
        if ($modo !== 'database') {
            return ['modo' => $modo, 'filas' => [], 'falhados' => 0];
        }

        $filas = [];
        foreach (['default', 'pesado'] as $nome) {
            $espera = DB::table('jobs')->where('queue', $nome)->count();
            $maisAntigo = DB::table('jobs')->where('queue', $nome)->min('available_at');
            $minutos = $maisAntigo === null ? 0 : (int) floor((time() - (int) $maisAntigo) / 60);

            $filas[] = ['nome' => $nome, 'em_espera' => $espera, 'minutos' => $minutos, 'parada' => $espera > 0 && $minutos >= self::MINUTOS_PARA_ALERTA];
        }

        return ['modo' => $modo, 'filas' => $filas, 'falhados' => DB::table('failed_jobs')->count()];
    }
}
