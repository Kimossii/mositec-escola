<?php

namespace Tests\Feature\Arquitectura;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** O Portal só consome (Usuario, Permissao, Core): nenhum outro módulo o importa. */
class FronteiraPortalTest extends TestCase
{
    public function test_nenhum_outro_modulo_importa_o_portal(): void
    {
        $violacoes = [];

        foreach (['Usuario', 'Permissao', 'Core', 'Aluno', 'Avaliacao', 'Frequencia', 'Financeiro', 'Horario'] as $modulo) {
            $raiz = dirname(__DIR__, 3) . "/Modules/{$modulo}";

            foreach (['app', 'routes'] as $pasta) {
                if (! is_dir("{$raiz}/{$pasta}")) {
                    continue;
                }

                $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$raiz}/{$pasta}", RecursiveDirectoryIterator::SKIP_DOTS));

                foreach ($iterador as $ficheiro) {
                    if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php' && str_contains(file_get_contents($ficheiro->getPathname()), 'Modules\\Portal\\')) {
                        $violacoes[] = $ficheiro->getPathname();
                    }
                }
            }
        }

        $this->assertSame([], $violacoes, "Ninguém pode importar o Portal:\n" . implode("\n", $violacoes));
    }
}
