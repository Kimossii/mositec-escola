<?php

namespace Tests\Feature\Arquitectura;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * O Aluno depende do Usuario (DadosPessoa, User); o inverso seria uma dependência circular. O Usuario
 * só conhece o contrato do Core (ProcuraAlunoParaConta), implementado pelo Aluno.
 */
class FronteiraUsuarioAlunoTest extends TestCase
{
    public function test_o_modulo_usuario_nao_importa_o_modulo_aluno(): void
    {
        $raiz = dirname(__DIR__, 3) . '/Modules/Usuario';
        $violacoes = [];

        foreach (['app', 'routes'] as $pasta) {
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$raiz}/{$pasta}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php' && str_contains(file_get_contents($ficheiro->getPathname()), 'Modules\\Aluno\\')) {
                    $violacoes[] = $ficheiro->getPathname();
                }
            }
        }

        $this->assertSame([], $violacoes, "O Usuario não pode importar o Aluno (usa Modules\\Core\\Contracts\\ProcuraAlunoParaConta):\n" . implode("\n", $violacoes));
    }
}
