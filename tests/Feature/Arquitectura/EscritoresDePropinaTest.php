<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `valor_pago`, `capital_liquidado_em` e o `estado` Parcialmente Paga / Paga (2 e 3) de uma propina são
 * caches: só RecalcularPropina os escreve (spec Propinas §6). Varre `app` e `routes` da raiz e de todos os
 * módulos, por tokens (`token_get_all`), com os argumentos das chamadas lidos até ao parêntese que fecha,
 * por isso colchetes e parênteses aninhados não enganam a detecção. O helper de testes `comEstado` está em
 * `tests/`, fora do âmbito.
 *
 * Limites desta análise estática (não apanha): chaves dinâmicas (`[$campo => 1]`), chaves concatenadas
 * (`'valor_' . 'pago'`), propriedades variáveis (`$p->{$campo} = 1`), SQL em strings cruas
 * (`DB::statement('UPDATE propinas SET ...')`) e `estado` vindo de uma variável (`['estado' => $e]`).
 * A regra do `estado` 2/3 só se aplica em ficheiros que referenciam `Propina`, `->propina` ou a tabela
 * literal 'propinas'. É uma rede de segurança contra deslizes, não uma prova formal. Varre também os seeders
 * (raiz e módulos).
 *
 * F4: `valor` de uma propina já gerada só muda no RecalcularPropina (`reajustarValor`, na mesma escrita que o estado e o valor pago); `plano_propina_id` e `ordem` só na ReassociacaoDePropinas. A criação fica com CriadoresDePropinaTest.
 *
 * Quando houver outro escritor legítimo de um campo com o mesmo nome (ex.: o valor pago da multa, F5),
 * acrescenta-se aqui explicitamente.
 */
class EscritoresDePropinaTest extends TestCase
{
    private const ESCRITORES_PERMITIDOS = [
        'Modules/Financeiro/app/Services/RecalcularPropina.php',
        // F5: o `valor_pago` da MULTA é cache do RecalcularMulta (tabela propina_multas, não propinas).
        'Modules/Financeiro/app/Services/RecalcularMulta.php',
    ];

    private const CAMPOS_DE_CACHE = ['valor_pago', 'capital_liquidado_em'];

    private const METODOS_DE_ESCRITA = [
        'fill', 'forceFill', 'update', 'updateQuietly', 'create', 'forceCreate', 'make', 'firstOrCreate', 'firstOrNew',
        'updateOrCreate', 'updateOrInsert', 'setRawAttributes', 'insert', 'insertOrIgnore', 'insertGetId', 'upsert', 'setAttribute', 'increment', 'decrement',
    ];

    /** F4: quem pode actualizar estes campos de uma propina JÁ GERADA (a criação é do CriadoresDePropinaTest). */
    private const REAJUSTES_PERMITIDOS = [
        'valor' => [
            'Modules/Financeiro/app/Services/RecalcularPropina.php',
            // F5: o `valor` da MULTA (propina_multas) é escrito pelo comando (subida) e pela isenção/redução manual.
            'Modules/Financeiro/app/Services/AplicarMultasService.php',
            'Modules/Financeiro/app/Actions/IsentarReduzirMultaAction.php',
        ],
        'plano_propina_id' => ['Modules/Financeiro/app/Support/ReassociacaoDePropinas.php'],
        'ordem' => ['Modules/Financeiro/app/Support/ReassociacaoDePropinas.php'],
    ];

    private const METODOS_DE_ACTUALIZACAO = [
        'fill', 'forceFill', 'update', 'updateQuietly', 'updateOrCreate', 'updateOrInsert', 'setRawAttributes', 'setAttribute',
        'increment', 'decrement', 'upsert',
    ];

    private const ATRIBUICOES = ['=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_COALESCE_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_INC, T_DEC];

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

    /** O valor que começa em $i é 2, 3, EstadoCobranca::PARCIALMENTE_PAGA ou EstadoCobranca::PAGA? */
    private function eEstadoResolvido(array $tokens, int $i): bool
    {
        $valor = [];

        for ($j = $i; $j < count($tokens) && ! in_array($tokens[$j][0], [',', ')', ']', ';'], true) && count($valor) < 5; $j++) {
            $valor[] = $tokens[$j];
        }

        if (count($valor) === 1 && $valor[0][0] === T_LNUMBER && in_array($valor[0][1], ['2', '3'], true)) {
            return true;
        }

        foreach ($valor as $token) {
            if (in_array($token[1], ['PARCIALMENTE_PAGA', 'PAGA'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Recua pela cadeia `$a->b->c` até à variável e vê se há `++`/`--` imediatamente antes. */
    private function precedidoPorIncrementoAnterior(array $tokens, int $i): bool
    {
        $j = $i - 1;

        while ($j >= 1 && $tokens[$j][0] === T_STRING && in_array($tokens[$j - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            $j -= 2;
        }

        return $j >= 1 && $tokens[$j][0] === T_VARIABLE && in_array($tokens[$j - 1][0], [T_INC, T_DEC], true);
    }

    /**
     * @return list<string> excertos que escrevem um dos campos
     */
    private function escritas(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $referePropina = $this->referePropina($tokens);

        $escritas = [];

        for ($i = 0; $i < $total; $i++) {
            // 0. Incremento/decremento anterior: ++$x->valor_pago, --$x->a->b->valor_pago
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && ($tokens[$i + 1][0] ?? null) === T_STRING
                && in_array($tokens[$i + 1][1], self::CAMPOS_DE_CACHE, true)
                && $this->precedidoPorIncrementoAnterior($tokens, $i)) {
                $escritas[] = $this->texto($tokens, $i - 2, min($i + 1, $total - 1));

                continue;
            }

            // 1. Atribuição a uma propriedade: $x->valor_pago = ..., $x->estado = 3
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && isset($tokens[$i + 2])
                && $tokens[$i + 1][0] === T_STRING
                && in_array($tokens[$i + 2][0], self::ATRIBUICOES, true)) {
                $campo = $tokens[$i + 1][1];

                if (in_array($campo, self::CAMPOS_DE_CACHE, true)
                    || ($campo === 'estado' && $referePropina && $this->eEstadoResolvido($tokens, $i + 3))) {
                    $escritas[] = $this->texto($tokens, $i, min($i + 5, $total - 1));
                }

                continue;
            }

            // 2. Chamadas que gravam: lê todos os argumentos até ao parêntese que fecha.
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

                $campoCache = $this->eString($tokens[$j], self::CAMPOS_DE_CACHE);
                $campoEstado = $referePropina && $this->eString($tokens[$j], ['estado']);

                if (! $campoCache && ! $campoEstado) {
                    continue;
                }

                // chave de array ('campo' => valor) ou 1.º argumento de setAttribute/increment/decrement
                $chave = ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW;
                $argumento = in_array($metodo, ['setAttribute', 'increment', 'decrement'], true) && $tokens[$j - 1][0] === '(';

                if (! $chave && ! $argumento) {
                    continue;
                }

                if ($campoEstado && ! $this->eEstadoResolvido($tokens, $j + 2)) {
                    continue;
                }

                $escritas[] = $this->texto($tokens, $j, min($j + 3, $total - 1));
            }
        }

        return $escritas;
    }

    private function referePropina(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ($token[1] === 'Propina' || str_ends_with($token[1], '\\Propina') || $token[1] === 'propina')) {
                return true;
            }

            if ($this->eString($token, ['propinas'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Actualizações de `valor`, `plano_propina_id` e `ordem` em ficheiros que referem Propina.
     *
     * @return list<array{0: string, 1: string}> [campo, excerto]
     */
    private function reajustes(string $codigo): array
    {
        $tokens = $this->tokens($codigo);

        if (! $this->referePropina($tokens)) {
            return [];
        }

        $campos = array_keys(self::REAJUSTES_PERMITIDOS);
        $total = count($tokens);
        $reajustes = [];

        for ($i = 0; $i < $total; $i++) {
            // Atribuição a uma propriedade de outro objecto: $p->valor = …, $p->ordem++ (não $this->valor = …).
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && isset($tokens[$i + 2])
                && $tokens[$i + 1][0] === T_STRING
                && in_array($tokens[$i + 1][1], $campos, true)
                && in_array($tokens[$i + 2][0], self::ATRIBUICOES, true)
                && ! (($tokens[$i - 1][0] ?? null) === T_VARIABLE && $tokens[$i - 1][1] === '$this')) {
                $reajustes[] = [$tokens[$i + 1][1], $this->texto($tokens, max(0, $i - 1), min($i + 4, $total - 1))];

                continue;
            }

            // `$p->plano()->associate(...)` / `->dissociate()` escrevem plano_propina_id pela relação.
            if ($tokens[$i][0] === T_STRING
                && in_array($tokens[$i][1], ['associate', 'dissociate'], true)
                && ($tokens[$i + 1][0] ?? null) === '('
                && ($tokens[$i - 1][0] ?? null) === T_OBJECT_OPERATOR
                && ($tokens[$i - 2][0] ?? null) === ')'
                && ($tokens[$i - 3][0] ?? null) === '('
                && ($tokens[$i - 4][1] ?? null) === 'plano') {
                $reajustes[] = ['plano_propina_id', $this->texto($tokens, $i - 4, min($i + 2, $total - 1))];

                continue;
            }

            // Atribuição a um índice de array: $atributos['valor'] = …, $p->attributes['ordem'] += …
            if ($tokens[$i][0] === '['
                && isset($tokens[$i + 3])
                && $this->eString($tokens[$i + 1], $campos)
                && $tokens[$i + 2][0] === ']'
                && in_array($tokens[$i + 3][0], self::ATRIBUICOES, true)) {
                $reajustes[] = [trim($tokens[$i + 1][1], '\'"'), $this->texto($tokens, max(0, $i - 1), min($i + 4, $total - 1))];

                continue;
            }

            if ($tokens[$i][0] !== T_STRING
                || ! in_array($tokens[$i][1], self::METODOS_DE_ACTUALIZACAO, true)
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

                if (! $this->eString($tokens[$j], $campos)) {
                    continue;
                }

                $chave = ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW;
                $argumento = in_array($metodo, ['setAttribute', 'increment', 'decrement'], true) && $tokens[$j - 1][0] === '(';

                if ($chave || $argumento) {
                    $reajustes[] = [trim($tokens[$j][1], '\'"'), $this->texto($tokens, $j, min($j + 3, $total - 1))];
                }
            }
        }

        return $reajustes;
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
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php') {
                    $ficheiros[str_replace($this->raiz() . '/', '', $ficheiro->getPathname())] = file_get_contents($ficheiro->getPathname());
                }
            }
        }

        return $ficheiros;
    }

    public function test_ha_codigo_para_analisar_em_todos_os_modulos(): void
    {
        $caminhos = array_keys($this->codigoVarrido());

        $this->assertContains('Modules/Financeiro/app/Models/Propina.php', $caminhos);
        $this->assertContains('Modules/Matricula/app/Models/Matricula.php', $caminhos);
        $this->assertNotSame([], array_filter($caminhos, fn (string $c): bool => str_contains($c, '/routes/')));
    }

    public function test_so_o_recalcular_propina_escreve_os_campos_de_cache_da_propina(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if (in_array($relativo, self::ESCRITORES_PERMITIDOS, true)) {
                continue;
            }

            foreach ($this->escritas($codigo) as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só RecalcularPropina escreve valor_pago, capital_liquidado_em e estado 2/3:\n" . implode("\n", $violacoes));
    }

    public function test_o_escritor_permitido_existe_e_e_apanhado_pelo_varrimento(): void
    {
        $caminho = $this->raiz() . '/' . self::ESCRITORES_PERMITIDOS[0];

        $this->assertFileExists($caminho);
        $this->assertNotSame([], $this->escritas(file_get_contents($caminho)));
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->escritas('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'atribuição directa' => ['$p->valor_pago = Dinheiro::deUnidadesMenores(1);', true],
            'atribuição de propina' => ['$propina->valor_pago = 5;', true],
            'atribuição composta' => ['$p->valor_pago += 5;', true],
            'capital liquidado atribuído' => ['$p->capital_liquidado_em = now();', true],
            'forceFill' => ["\$p->forceFill(['estado' => 1, 'valor_pago' => 0])->save();", true],
            'forceFill com colchetes aninhados antes da chave' => ["\$p->forceFill(['x' => \$a['k'], 'valor_pago' => 1]);", true],
            'forceFill com chamadas e arrays aninhados' => ["\$p->forceFill(['a' => f(g([1, 2]), \$b['c']['d']), 'capital_liquidado_em' => null]);", true],
            'update' => ["\$p->update(['capital_liquidado_em' => null]);", true],
            'update em query' => ["Propina::query()->where('id', 1)->update(['valor_pago' => 0]);", true],
            'estado 3 em query de Propina' => ["Propina::query()->update(['estado' => 3]);", true],
            'estado 2 literal' => ["Propina::query()->update(['x' => \$a['k'], 'estado' => 2]);", true],
            'estado por enum Paga' => ["use Modules\\Financeiro\\Models\\Propina; \$p->forceFill(['estado' => EstadoCobranca::PAGA]);", true],
            'estado por enum Parcialmente Paga' => ["Propina::query()->update(['estado' => EstadoCobranca::PARCIALMENTE_PAGA]);", true],
            'estado atribuído' => ["\$p = Propina::find(1); \$p->estado = EstadoCobranca::PAGA;", true],
            'setAttribute' => ["\$p->setAttribute('valor_pago', 1);", true],
            'setAttribute de estado' => ["Propina::class; \$p->setAttribute('estado', 3);", true],
            'increment' => ["\$q->increment('valor_pago', 10);", true],
            'insert' => ["Propina::insert([['valor_pago' => 1]]);", true],
            'estado Cancelada não é cache' => ["Propina::query()->update(['estado' => EstadoCobranca::CANCELADA]);", false],
            'estado 4 não é cache' => ["Propina::query()->update(['estado' => 4]);", false],
            'estado 3 noutro model' => ["Turma::query()->update(['estado' => 3]);", false],
            'estado 3 atribuído noutro model' => ['$t->estado = 3;', false],
            'leitura' => ['$x = $p->valor_pago;', false],
            'comparações' => ['if ($p->valor_pago == $v || $p->valor_pago === $w || $p->valor_pago >= $z) {}', false],
            'leitura encadeada' => ['$p->valor_pago?->unidadesMenores();', false],
            'casts do model' => ["protected \$casts = ['valor_pago' => DinheiroCast::class];", false],
            'atributos por omissão' => ["protected \$attributes = ['estado' => 1, 'valor_pago' => 0];", false],
            'chave em array que não grava' => ["\$linha = ['valor_pago' => \$p->valor_pago];", false],
            'updateOrInsert' => ["DB::table('x')->updateOrInsert(['id' => 1], ['valor_pago' => 5]);", true],
            'setRawAttributes' => ["\$p->setRawAttributes(['valor_pago' => 5]);", true],
            'incremento posterior' => ['$p->valor_pago++;', true],
            'decremento posterior' => ['$p->valor_pago--;', true],
            'incremento anterior' => ['++$p->valor_pago;', true],
            'decremento anterior' => ['--$p->capital_liquidado_em;', true],
            'concatenação composta' => ['$p->valor_pago .= 1;', true],
            'divisão composta' => ['$p->valor_pago /= 2;', true],
            'estado 3 na tabela literal' => ["DB::table('propinas')->update(['estado' => 3]);", true],
            'estado 2 na tabela literal' => ["DB::table('propinas')->where('id', 1)->update(['estado' => 2]);", true],
            'estado 1 na tabela literal' => ["DB::table('propinas')->update(['estado' => 1]);", false],
            'estado 3 noutra tabela literal' => ["DB::table('turmas')->update(['estado' => 3]);", false],
            'estado 3 via relação propina' => ["\$pagamento->propina->forceFill(['estado' => 3]);", true],
            'estado 3 via relação propina atribuído' => ['$pagamento->propina->estado = 3;', true],
            'estado 1 via relação propina' => ["\$pagamento->propina->forceFill(['estado' => 1]);", false],
            'valor_pago via relação propina' => ['$pagamento->propina->valor_pago = 1;', true],
            'cancelamento (estado 4 e cancelado_*) não é cache' => ["\$p->forceFill(['estado' => EstadoCobranca::CANCELADA, 'motivo_cancelamento' => 'x', 'cancelado_em' => \$agora])->save();", false],
            'comentário' => ["// \$p->valor_pago = 1;\n/* \$p->update(['valor_pago' => 1]); */", false],
        ];
    }

    public function test_so_os_pontos_de_reajuste_actualizam_valor_plano_e_ordem_de_uma_propina(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            foreach ($this->reajustes($codigo) as [$campo, $excerto]) {
                if (! in_array($relativo, self::REAJUSTES_PERMITIDOS[$campo], true)) {
                    $violacoes[] = "{$relativo}: {$excerto}";
                }
            }
        }

        $this->assertSame([], $violacoes, "Só RecalcularPropina actualiza o valor e só ReassociacaoDePropinas o plano e a ordem de uma propina:\n" . implode("\n", $violacoes));
    }

    public function test_os_pontos_de_reajuste_existem_e_sao_apanhados_pelo_varrimento(): void
    {
        foreach (self::REAJUSTES_PERMITIDOS as $campo => $ficheiros) {
            foreach ($ficheiros as $ficheiro) {
                $caminho = $this->raiz() . '/' . $ficheiro;

                $this->assertFileExists($caminho);
                $this->assertContains($campo, array_column($this->reajustes(file_get_contents($caminho)), 0), "{$ficheiro} devia escrever {$campo}.");
            }
        }
    }

    #[DataProvider('exemplosDeReajuste')]
    public function test_o_varrimento_de_reajustes_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->reajustes('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplosDeReajuste(): array
    {
        return [
            'forceFill de valor' => ["use Modules\\Financeiro\\Models\\Propina; \$p->forceFill(['valor' => \$v])->save();", true],
            'update de plano em query' => ["Propina::query()->whereKey(1)->update(['plano_propina_id' => 2]);", true],
            'atribuição de ordem' => ['$p = Propina::find(1); $p->ordem = 3;', true],
            'incremento de ordem' => ['Propina::class; $p->ordem++;', true],
            'setAttribute de valor' => ["Propina::class; \$p->setAttribute('valor', 1);", true],
            'via relação propina' => ["\$ajuste->propina->forceFill(['ordem' => 2]);", true],
            'associate pela relação plano()' => ['Propina::class; $p->plano()->associate($novo);', true],
            'dissociate pela relação plano()' => ['Propina::class; $p->plano()->dissociate();', true],
            'associate de outra relação' => ['Propina::class; $p->matricula()->associate($m);', false],
            'valor atribuído por índice' => ["Propina::class; \$atributos['valor'] = 5;", true],
            'ordem composta por índice' => ["Propina::class; \$atributos['ordem'] += 1;", true],
            'índice lido ou comparado' => ["Propina::class; \$x = \$a['valor']; if (\$a['valor'] == 1) {}", false],
            'criação não conta (CriadoresDePropinaTest)' => ["Propina::create(['valor' => 1, 'plano_propina_id' => 2, 'ordem' => 1]);", false],
            'valor do plano num ficheiro sem Propina' => ["PlanoPropina::query()->whereKey(1)->update(['valor' => 1]);", false],
            'propriedade do próprio objecto' => ['Propina::class; $this->valor = $v;', false],
            'leituras' => ['Propina::class; $x = $p->valor; $o = $p->ordem === 1;', false],
            'ordenação' => ["Propina::query()->orderBy('ordem')->get();", false],
            'array que não grava' => ["Propina::class; \$linha = ['valor' => \$p->valor, 'ordem' => 1];", false],
        ];
    }

    private const REGISTOS_IMUTAVEIS = ['AlteracaoPrecoPlano', 'PropinaAjuste'];

    private const TABELAS_IMUTAVEIS = ['alteracoes_preco_plano', 'propina_ajustes'];

    private const METODOS_DE_ALTERACAO_OU_REMOCAO = [
        'update', 'updateQuietly', 'updateOrCreate', 'updateOrInsert', 'upsert', 'delete', 'deleteQuietly', 'forceDelete',
        'destroy', 'truncate', 'increment', 'decrement', 'insertUsing',
    ];

    /**
     * Alterações ou remoções (query builder ou model) encadeadas, na mesma instrução, num dos registos imutáveis
     * (`AlteracaoPrecoPlano`, `PropinaAjuste` ou as suas tabelas). Os próprios models ficam de fora.
     *
     * @return list<string>
     */
    private function mutacoesDeRegistosImutaveis(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $achados = [];

        for ($i = 0; $i < $total; $i++) {
            $alvo = (in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && in_array(substr(strrchr('\\' . $tokens[$i][1], '\\'), 1), self::REGISTOS_IMUTAVEIS, true))
                || $this->eString($tokens[$i], self::TABELAS_IMUTAVEIS);

            if (! $alvo || ($tokens[$i - 1][0] ?? null) === T_USE || ($tokens[$i - 1][0] ?? null) === T_NAMESPACE) {
                continue;
            }

            for ($j = $i + 1; $j < $total && $tokens[$j][0] !== ';'; $j++) {
                if ($tokens[$j][0] === T_STRING
                    && in_array($tokens[$j][1], self::METODOS_DE_ALTERACAO_OU_REMOCAO, true)
                    && ($tokens[$j + 1][0] ?? null) === '('
                    && in_array($tokens[$j - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    $achados[] = $this->texto($tokens, $i, $j + 1);

                    break;
                }
            }
        }

        return $achados;
    }

    public function test_os_registos_de_alteracao_de_preco_so_se_criam_nunca_se_alteram_nem_apagam(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if (in_array(basename($relativo, '.php'), self::REGISTOS_IMUTAVEIS, true) && str_contains($relativo, '/Models/')) {
                continue;
            }

            foreach ($this->mutacoesDeRegistosImutaveis($codigo) as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "AlteracaoPrecoPlano e PropinaAjuste só se criam (spec §14); reverter é uma nova alteração:\n" . implode("\n", $violacoes));
    }

    #[DataProvider('exemplosDeImutaveis')]
    public function test_o_varrimento_de_imutaveis_apanha_o_que_deve(string $codigo, bool $mutacao): void
    {
        $this->assertSame($mutacao, $this->mutacoesDeRegistosImutaveis('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplosDeImutaveis(): array
    {
        return [
            'update em query do model' => ["AlteracaoPrecoPlano::query()->whereKey(1)->update(['motivo' => 'x']);", true],
            'delete em query do model' => ['PropinaAjuste::query()->where("id", 1)->delete();', true],
            'upsert estático' => ["PropinaAjuste::upsert([['motivo' => 'x']], ['id']);", true],
            'destroy estático' => ['AlteracaoPrecoPlano::destroy(1);', true],
            'update pela tabela' => ["DB::table('propina_ajustes')->update(['motivo' => 'x']);", true],
            'delete pela tabela' => ["DB::table('alteracoes_preco_plano')->delete();", true],
            'FQN' => ['\\Modules\\Financeiro\\Models\\PropinaAjuste::query()->delete();', true],
            'criação' => ["PropinaAjuste::create(['motivo' => 'x']); \$plano->update(['valor' => 1]);", false],
            'leitura' => ['PropinaAjuste::query()->where("propina_id", 1)->get();', false],
            'update de outro model noutra instrução' => ["AlteracaoPrecoPlano::create([]);\n\$plano->update(['valor' => 1]);", false],
            'import' => ['use Modules\\Financeiro\\Models\\PropinaAjuste;', false],
        ];
    }
}
