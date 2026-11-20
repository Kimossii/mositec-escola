<?php

namespace Tests\Feature\Arquitectura;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * O Licenciamento é um módulo independente: só pode importar o Core. Os outros módulos (Financeiro, Avaliação, Usuario...)
 * falam com ele através de contratos do Core, nunca directamente.
 */
class FronteiraLicenciamentoTest extends TestCase
{
    public function test_licenciamento_so_importa_o_core(): void
    {
        $raiz = dirname(__DIR__, 3) . '/Modules/Licenciamento/app';

        $this->assertDirectoryExists($raiz, 'O módulo Licenciamento não existe.');

        $violacoes = [];
        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterador as $ficheiro) {
            if (! $ficheiro->isFile() || $ficheiro->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/Modules\\\\([A-Za-z0-9_]+)\\\\/', file_get_contents($ficheiro->getPathname()), $encontrados);

            foreach (array_unique($encontrados[1]) as $modulo) {
                if (! in_array($modulo, ['Licenciamento', 'Core'], true)) {
                    $violacoes[] = "{$ficheiro->getPathname()} importa Modules\\{$modulo}";
                }
            }
        }

        $this->assertSame([], $violacoes, "O Licenciamento só pode importar o Core:\n" . implode("\n", $violacoes));
    }
}
