<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;

/**
 * O Financeiro depende da Matrícula (propinas pertencem a matrículas); o inverso seria circular. A Matrícula só
 * emite o seu evento (MatriculaEstadoAlterado, com primitivos) e consulta o seu contrato (DependenciasDaMatricula),
 * ambos implementados/escutados pelo Financeiro (F3).
 */
class FronteiraMatriculaFinanceiroTest extends TestCase
{
    public function test_o_modulo_matricula_nao_importa_o_modulo_financeiro(): void
    {
        $raiz = dirname(__DIR__, 3) . '/Modules/Matricula';
        $violacoes = [];
        $analisados = 0;

        foreach (['app', 'routes'] as $pasta) {
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$raiz}/{$pasta}", FilesystemIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if (! $ficheiro->isFile() || $ficheiro->getExtension() !== 'php') {
                    continue;
                }

                $analisados++;

                if ($this->referenciaOFinanceiro(file_get_contents($ficheiro->getPathname()))) {
                    $violacoes[] = $ficheiro->getPathname();
                }
            }
        }

        $this->assertGreaterThan(0, $analisados);
        $this->assertSame([], $violacoes, "A Matrícula não pode importar o Financeiro:\n" . implode("\n", $violacoes));
    }

    public function test_o_evento_da_matricula_so_leva_primitivos(): void
    {
        $parametros = (new ReflectionClass(MatriculaEstadoAlterado::class))->getConstructor()->getParameters();

        $this->assertSame(
            ['matriculaId', 'estadoAnterior', 'estadoNovo', 'dataEfeito', 'utilizadorId'],
            array_map(fn ($parametro) => $parametro->getName(), $parametros),
        );

        foreach ($parametros as $parametro) {
            $tipo = $parametro->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $tipo, $parametro->getName());
            $this->assertTrue($tipo->isBuiltin(), "{$parametro->getName()} tem de ser um primitivo (nunca um model).");
        }
    }

    /** Qualquer menção ao namespace do Financeiro (use, use com alias, FQN inline, com ou sem `\` inicial). */
    private function referenciaOFinanceiro(string $codigo): bool
    {
        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && str_starts_with(ltrim($token[1], '\\'), 'Modules\\Financeiro\\')) {
                return true;
            }
        }

        return false;
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $referencia): void
    {
        $this->assertSame($referencia, $this->referenciaOFinanceiro('<?php ' . $codigo), $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'use simples' => ['use Modules\\Financeiro\\Models\\Propina;', true],
            'use com alias' => ['use Modules\\Financeiro\\Models\\Propina as P;', true],
            'use de grupo' => ['use Modules\\Financeiro\\Models\\{Propina, Pagamento};', true],
            'FQN inline com barra inicial' => ['$x = \\Modules\\Financeiro\\Models\\Propina::query();', true],
            'FQN inline sem barra inicial' => ['$x = new Modules\\Financeiro\\Models\\Propina();', true],
            'class-string' => ['$c = \\Modules\\Financeiro\\Models\\Propina::class;', true],
            'outro módulo' => ['use Modules\\Turma\\Models\\Turma;', false],
            'nome parecido' => ['use Modules\\FinanceiroExtra\\Models\\X;', false],
            'só no comentário' => ["// Modules\\Financeiro\\Models\\Propina\n/* use Modules\\Financeiro\\X; */", false],
        ];
    }
}
