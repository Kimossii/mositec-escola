<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ponto único de cancelamento (D2 do plano F3): o estado Cancelada e `motivo_cancelamento`, `cancelado_por` e
 * `cancelado_em` só se escrevem em CancelamentoDePropinas, onde vivem as pré-condições de §8 (Em Aberto, sem
 * pagamentos, sem histórico). Mesma técnica e mesmos limites de EscritoresDePropinaTest (tokens; não apanha chaves
 * dinâmicas, SQL cru em strings nem `estado` vindo de variáveis). O helper de testes `comEstado` está em `tests/`.
 */
class CanceladoresDePropinaTest extends TestCase
{
    private const CANCELADORES_PERMITIDOS = ['Modules/Financeiro/app/Support/CancelamentoDePropinas.php'];

    private const CAMPOS_DE_CANCELAMENTO = ['motivo_cancelamento', 'cancelado_por', 'cancelado_em'];

    private const METODOS_DE_ESCRITA = [
        'fill', 'forceFill', 'update', 'updateQuietly', 'create', 'forceCreate', 'make', 'firstOrCreate', 'firstOrNew',
        'updateOrCreate', 'updateOrInsert', 'setRawAttributes', 'insert', 'insertOrIgnore', 'insertGetId', 'upsert', 'setAttribute',
    ];

    private const ATRIBUICOES = ['=', T_COALESCE_EQUAL, T_CONCAT_EQUAL];

    /** @return list<array{0: int|string, 1: string}> */
    private function tokens(string $codigo): array
    {
        $saida = [];

        foreach (token_get_all($codigo) as $token) {
            $token = is_array($token) ? [$token[0], $token[1]] : [$token, $token];

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $saida[] = $token;
        }

        return $saida;
    }

    private function texto(array $tokens, int $de, int $ate): string
    {
        return implode(' ', array_map(fn (array $t): string => $t[1], array_slice($tokens, $de, $ate - $de + 1)));
    }

    private function eString(array $token, array $nomes): bool
    {
        return $token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($token[1], '\'"'), $nomes, true);
    }

    /** O valor que começa em $i é 4 ou EstadoCobranca::CANCELADA? (EstadoMatriculaEnum::CANCELADA não conta.) */
    private function eCancelada(array $tokens, int $i): bool
    {
        $valor = [];

        for ($j = $i; $j < count($tokens) && ! in_array($tokens[$j][0], [',', ')', ']', ';'], true) && count($valor) < 5; $j++) {
            $valor[] = $tokens[$j];
        }

        if (count($valor) === 1 && $valor[0][0] === T_LNUMBER && $valor[0][1] === '4') {
            return true;
        }

        for ($k = 0; $k + 2 < count($valor); $k++) {
            if (str_ends_with($valor[$k][1], 'EstadoCobranca') && $valor[$k + 1][0] === T_DOUBLE_COLON && $valor[$k + 2][1] === 'CANCELADA') {
                return true;
            }
        }

        return false;
    }

    private function referePropina(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && ($token[1] === 'Propina' || str_ends_with($token[1], '\\Propina') || $token[1] === 'propina')) {
                return true;
            }

            if ($this->eString($token, ['propinas'])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> excertos que cancelam */
    private function cancelamentos(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $referePropina = $this->referePropina($tokens);
        $achados = [];

        for ($i = 0; $i < $total; $i++) {
            // 1. Atribuição a uma propriedade: $p->cancelado_em = ..., $p->estado = EstadoCobranca::CANCELADA
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && isset($tokens[$i + 2])
                && $tokens[$i + 1][0] === T_STRING
                && in_array($tokens[$i + 2][0], self::ATRIBUICOES, true)) {
                $campo = $tokens[$i + 1][1];

                if (in_array($campo, self::CAMPOS_DE_CANCELAMENTO, true)
                    || ($campo === 'estado' && $referePropina && $this->eCancelada($tokens, $i + 3))) {
                    $achados[] = $this->texto($tokens, $i, min($i + 5, $total - 1));
                }

                continue;
            }

            // 2. Chamadas que gravam: lê os argumentos até ao parêntese que fecha.
            if ($tokens[$i][0] !== T_STRING
                || ! in_array($tokens[$i][1], self::METODOS_DE_ESCRITA, true)
                || ($tokens[$i + 1][0] ?? null) !== '('
                || ! in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $metodo = $tokens[$i][1];
            $nivel = 0;

            for ($j = $i + 1; $j < $total; $j++) {
                if (in_array($tokens[$j][0], ['(', '[', T_CURLY_OPEN, '{'], true)) {
                    $nivel++;
                } elseif (in_array($tokens[$j][0], [')', ']', '}'], true)) {
                    $nivel--;

                    if ($nivel === 0) {
                        break;
                    }
                }

                $campoCancelamento = $this->eString($tokens[$j], self::CAMPOS_DE_CANCELAMENTO);
                $campoEstado = $referePropina && $this->eString($tokens[$j], ['estado']);

                if (! $campoCancelamento && ! $campoEstado) {
                    continue;
                }

                $chave = ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW;
                $argumento = $metodo === 'setAttribute' && $tokens[$j - 1][0] === '(';

                if (! $chave && ! $argumento) {
                    continue;
                }

                if ($campoEstado && ! $this->eCancelada($tokens, $j + 2)) {
                    continue;
                }

                $achados[] = $this->texto($tokens, $j, min($j + 3, $total - 1));
            }
        }

        return $achados;
    }

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> caminho relativo => conteúdo */
    private function codigoVarrido(): array
    {
        $pastas = array_filter([
            $this->raiz() . '/app',
            $this->raiz() . '/routes',
            $this->raiz() . '/database/seeders',
            ...glob($this->raiz() . '/Modules/*/database/seeders'),
            ...glob($this->raiz() . '/Modules/*/app'),
            ...glob($this->raiz() . '/Modules/*/routes'),
        ], 'is_dir');
        $ficheiros = [];

        foreach ($pastas as $pasta) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS)) as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php') {
                    $ficheiros[str_replace($this->raiz() . '/', '', $ficheiro->getPathname())] = file_get_contents($ficheiro->getPathname());
                }
            }
        }

        return $ficheiros;
    }

    public function test_so_o_cancelamento_de_propinas_cancela(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if (in_array($relativo, self::CANCELADORES_PERMITIDOS, true)) {
                continue;
            }

            foreach ($this->cancelamentos($codigo) as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só CancelamentoDePropinas escreve o estado Cancelada e os campos de cancelamento:\n" . implode("\n", $violacoes));
    }

    public function test_o_cancelador_permitido_existe_e_e_apanhado_pelo_varrimento(): void
    {
        $caminho = $this->raiz() . '/' . self::CANCELADORES_PERMITIDOS[0];

        $this->assertFileExists($caminho);
        $this->assertNotSame([], $this->cancelamentos(file_get_contents($caminho)));
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $cancelamento): void
    {
        $this->assertSame($cancelamento, $this->cancelamentos('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'forceFill com motivo' => ["\$p->forceFill(['motivo_cancelamento' => 'x'])->save();", true],
            'update com cancelado_em' => ["Propina::query()->whereKey(1)->update(['cancelado_em' => \$agora]);", true],
            'atribuição de cancelado_por' => ['$p->cancelado_por = 1;', true],
            'atribuição via relação propina' => ["\$pagamento->propina->motivo_cancelamento = 'x';", true],
            'setAttribute de cancelado_em' => ["\$p->setAttribute('cancelado_em', \$agora);", true],
            'estado Cancelada por enum em query de Propina' => ["Propina::query()->update(['estado' => EstadoCobranca::CANCELADA]);", true],
            'estado 4 literal em query de Propina' => ["Propina::query()->update(['estado' => 4]);", true],
            'estado Cancelada atribuído a uma propina' => ['$p = Propina::find(1); $p->estado = EstadoCobranca::CANCELADA;', true],
            'estado Cancelada na tabela literal' => ["DB::table('propinas')->update(['estado' => 4]);", true],
            'matrícula cancelada não é propina' => ["use Modules\\Financeiro\\Models\\Propina; \$m->update(['estado' => EstadoMatriculaEnum::CANCELADA]);", false],
            'estado 4 noutro model' => ["Turma::query()->update(['estado' => 4]);", false],
            'estado Paga não é cancelamento' => ["Propina::query()->update(['estado' => EstadoCobranca::PAGA]);", false],
            'leitura' => ['$x = $p->motivo_cancelamento;', false],
            'comparação' => ['if ($p->estado === EstadoCobranca::CANCELADA) {}', false],
            'filtro' => ["Propina::query()->where('estado', EstadoCobranca::CANCELADA)->get();", false],
            'array de saída' => ["\$linha = ['motivo_cancelamento' => \$p->motivo_cancelamento, 'cancelado_em' => \$p->cancelado_em];", false],
            'casts do model' => ["protected \$casts = ['cancelado_em' => 'datetime'];", false],
            'comentário' => ["// \$p->cancelado_em = 1;\n/* \$p->update(['motivo_cancelamento' => 'x']); */", false],
        ];
    }
}
