<?php

namespace Tests\Feature\Arquitectura;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * O Financeiro depende do AnoLectivo; o inverso seria circular. O AnoLectivo só conhece o contrato
 * DependenciasDoAnoLectivo, implementado pelo Financeiro.
 */
class FronteiraAnoLectivoFinanceiroTest extends TestCase
{
    public function test_o_modulo_ano_lectivo_nao_importa_o_modulo_financeiro(): void
    {
        $raiz = dirname(__DIR__, 3) . '/Modules/AnoLectivo';
        $violacoes = [];

        foreach (['app', 'routes'] as $pasta) {
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$raiz}/{$pasta}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php' && str_contains(file_get_contents($ficheiro->getPathname()), 'Modules\\Financeiro\\')) {
                    $violacoes[] = $ficheiro->getPathname();
                }
            }
        }

        $this->assertSame([], $violacoes, "O AnoLectivo não pode importar o Financeiro (usa Modules\\AnoLectivo\\Contracts\\DependenciasDoAnoLectivo):\n" . implode("\n", $violacoes));
    }
}
