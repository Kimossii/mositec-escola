<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ponto único de anulação de propinas (D16 de Pagamentos-A): o estado Anulada de uma propina só se escreve em
 * AnulacaoDePropinas, onde vivem as pré-condições de §8 (Em Aberto, sem valor pago, sem contribuições, COM histórico).
 * Os campos `motivo_anulacao`, `anulado_por`, `anulado_em` também existem em pagamentos e utilizações de crédito: só os
 * três escritores listados os escrevem (cada um, por convenção, nos seus registos: ver limites abaixo).
 * Mesma técnica e limites de CanceladoresDePropinaTest. Limites desta regra (aceites, para não os ler como garantias):
 *  - o estado Anulada e os campos de anulação só são detectados em ficheiros que "falam de Propina" (T_STRING
 *    `Propina`/`propina` ou o literal 'propinas'); um escritor que receba propinas de `BloqueioDePropinas` sem importar
 *    `Propina` escaparia (o exemplo "anulação completa de propina" espera estado=false precisamente por isso);
 *  - a regra dos campos garante QUEM os escreve (os três ficheiros), não que cada Action os escreva só nos seus
 *    registos: AnularPagamentoAction podia escrever `motivo_anulacao` numa propina sem esta regra falhar.
 */
class AnuladoresDePropinaTest extends TestCase
{
    private const ANULADOR_DE_PROPINAS = 'Modules/Financeiro/app/Support/AnulacaoDePropinas.php';

    private const ESCRITORES_DOS_CAMPOS_DE_ANULACAO = [
        'Modules/Financeiro/app/Support/AnulacaoDePropinas.php',
        'Modules/Financeiro/app/Actions/AnularPagamentoAction.php',
        'Modules/Financeiro/app/Actions/AnularUtilizacaoCreditoAction.php',
    ];

    private const CAMPOS_DE_ANULACAO = ['motivo_anulacao', 'anulado_por', 'anulado_em'];

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

    /** O valor que começa em $i é 5 ou EstadoCobranca::ANULADA? (EstadoUtilizacaoCredito::ANULADA não conta.) */
    private function eAnulada(array $tokens, int $i): bool
    {
        $valor = [];

        for ($j = $i; $j < count($tokens) && ! in_array($tokens[$j][0], [',', ')', ']', ';'], true) && count($valor) < 5; $j++) {
            $valor[] = $tokens[$j];
        }

        if (count($valor) === 1 && $valor[0][0] === T_LNUMBER && $valor[0][1] === '5') {
            return true;
        }

        for ($k = 0; $k + 2 < count($valor); $k++) {
            if (str_ends_with($valor[$k][1], 'EstadoCobranca') && $valor[$k + 1][0] === T_DOUBLE_COLON && $valor[$k + 2][1] === 'ANULADA') {
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

    /**
     * @return array{estado: list<string>, campos: list<string>} escritas do estado Anulada (de propina) e dos campos
     */
    private function anulacoes(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $referePropina = $this->referePropina($tokens);
        $achados = ['estado' => [], 'campos' => []];

        for ($i = 0; $i < $total; $i++) {
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && isset($tokens[$i + 2])
                && $tokens[$i + 1][0] === T_STRING
                && in_array($tokens[$i + 2][0], self::ATRIBUICOES, true)) {
                $campo = $tokens[$i + 1][1];

                if (in_array($campo, self::CAMPOS_DE_ANULACAO, true)) {
                    $achados['campos'][] = $this->texto($tokens, $i, min($i + 5, $total - 1));
                } elseif ($campo === 'estado' && $referePropina && $this->eAnulada($tokens, $i + 3)) {
                    $achados['estado'][] = $this->texto($tokens, $i, min($i + 5, $total - 1));
                }

                continue;
            }

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
                } elseif (in_array($tokens[$j][0], [')', ']', '}'], true) && --$nivel === 0) {
                    break;
                }

                $campoAnulacao = $this->eString($tokens[$j], self::CAMPOS_DE_ANULACAO);
                $campoEstado = $referePropina && $this->eString($tokens[$j], ['estado']);

                if (! $campoAnulacao && ! $campoEstado) {
                    continue;
                }

                $chave = ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW;
                $argumento = $metodo === 'setAttribute' && $tokens[$j - 1][0] === '(';

                if (! $chave && ! $argumento) {
                    continue;
                }

                if ($campoAnulacao) {
                    $achados['campos'][] = $this->texto($tokens, $j, min($j + 3, $total - 1));
                } elseif ($this->eAnulada($tokens, $j + 2)) {
                    $achados['estado'][] = $this->texto($tokens, $j, min($j + 3, $total - 1));
                }
            }
        }

        return $achados;
    }

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> */
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

    public function test_so_a_anulacao_de_propinas_anula_propinas(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if ($relativo === self::ANULADOR_DE_PROPINAS) {
                continue;
            }

            foreach ($this->anulacoes($codigo)['estado'] as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só AnulacaoDePropinas escreve o estado Anulada de uma propina:\n" . implode("\n", $violacoes));
    }

    public function test_so_os_tres_anuladores_escrevem_os_campos_de_anulacao(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if (in_array($relativo, self::ESCRITORES_DOS_CAMPOS_DE_ANULACAO, true)) {
                continue;
            }

            foreach ($this->anulacoes($codigo)['campos'] as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só AnulacaoDePropinas, AnularPagamentoAction e AnularUtilizacaoCreditoAction escrevem os campos de anulação:\n" . implode("\n", $violacoes));
    }

    public function test_os_anuladores_existem_e_sao_apanhados(): void
    {
        $this->assertNotSame([], $this->anulacoes(file_get_contents($this->raiz() . '/' . self::ANULADOR_DE_PROPINAS))['estado']);

        foreach (self::ESCRITORES_DOS_CAMPOS_DE_ANULACAO as $ficheiro) {
            $this->assertNotSame([], $this->anulacoes(file_get_contents($this->raiz() . '/' . $ficheiro))['campos'], $ficheiro);
        }
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $estado, bool $campos): void
    {
        $achados = $this->anulacoes('<?php ' . $codigo);

        $this->assertSame([$estado, $campos], [$achados['estado'] !== [], $achados['campos'] !== []], $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'estado Anulada por enum em query de Propina' => ["Propina::query()->update(['estado' => EstadoCobranca::ANULADA]);", true, false],
            'estado 5 literal na tabela' => ["DB::table('propinas')->update(['estado' => 5]);", true, false],
            'estado Anulada atribuído a uma propina' => ['$p = Propina::find(1); $p->estado = EstadoCobranca::ANULADA;', true, false],
            'anulação completa de propina' => ["\$p->forceFill(['estado' => EstadoCobranca::ANULADA, 'motivo_anulacao' => 'x', 'anulado_em' => \$agora])->save();", false, true],
            'campos num pagamento' => ["\$pagamento->forceFill(['estado' => EstadoPagamento::ANULADO, 'motivo_anulacao' => 'x']);", false, true],
            'utilização anulada não é propina' => ["use Modules\\Financeiro\\Models\\Propina; \$u->forceFill(['estado' => EstadoUtilizacaoCredito::ANULADA]);", false, false],
            'estado Cancelada não é anulação' => ["Propina::query()->update(['estado' => EstadoCobranca::CANCELADA]);", false, false],
            'atribuição de anulado_por' => ['$x->anulado_por = 1;', false, true],
            'leitura' => ['$x = $p->motivo_anulacao; if ($p->estado === EstadoCobranca::ANULADA) {}', false, false],
            'filtro' => ["Propina::query()->where('estado', EstadoCobranca::ANULADA)->get();", false, false],
            'array de saída' => ["\$linha = ['motivo_anulacao' => \$p->motivo_anulacao];", false, false],
            'comentário' => ["// \$p->anulado_em = 1;", false, false],
        ];
    }
}
