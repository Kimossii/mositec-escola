<?php

namespace Tests\Feature\Arquitectura;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * O Docente depende do Usuario (DadosPessoa, User, a criação da conta); o inverso seria uma dependência circular. O Usuario
 * só conhece o contrato do Core (ProcuraDocenteParaConta), implementado pelo Docente.
 */
class FronteiraUsuarioDocenteTest extends TestCase
{
    public function test_o_modulo_usuario_nao_importa_o_modulo_docente(): void
    {
        $raiz = dirname(__DIR__, 3) . '/Modules/Usuario';
        $violacoes = [];

        foreach (['app', 'routes'] as $pasta) {
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$raiz}/{$pasta}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php' && str_contains(file_get_contents($ficheiro->getPathname()), 'Modules\\Docente\\')) {
                    $violacoes[] = $ficheiro->getPathname();
                }
            }
        }

        $this->assertSame([], $violacoes, "O Usuario não pode importar o Docente (usa Modules\\Core\\Contracts\\ProcuraDocenteParaConta):\n" . implode("\n", $violacoes));
    }
}
