<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Pagamentos-A (spec Pagamentos §2.4–2.5): `valor_utilizado` e o estado de um crédito são caches do RecalcularCredito;
 * pagamentos, distribuições, créditos e utilizações só mudam por instância (passando pelos hooks de imutabilidade do
 * model), nunca por escrita em massa. Mesma técnica e mesmos limites de EscritoresDePropinaTest (tokens; não apanha
 * chaves dinâmicas, SQL em strings cruas nem variáveis com o nome do model). O `valor_pago` das propinas continua
 * protegido por EscritoresDePropinaTest (só RecalcularPropina).
 */
class EscritoresDePagamentoTest extends TestCase
{
    private const ESCRITOR_DO_CREDITO = 'Modules/Financeiro/app/Services/RecalcularCredito.php';

    private const MODELS = ['Pagamento', 'PagamentoPropina', 'Credito', 'CreditoUtilizacao'];

    private const TABELAS = ['pagamentos', 'pagamento_propinas', 'creditos', 'credito_utilizacoes'];

    private const ESCRITA_EM_MASSA = [
        'update', 'updateQuietly', 'updateOrInsert', 'upsert', 'delete', 'deleteQuietly', 'forceDelete', 'destroy',
        'truncate', 'increment', 'decrement', 'insert', 'insertOrIgnore', 'insertGetId', 'insertUsing',
    ];

    /** Relações que devolvem registos protegidos: `->relacao()->update|delete(...)` é escrita em massa. */
    private const RELACOES = ['distribuicoes', 'utilizacoes', 'utilizacoesCombinadas', 'utilizacoesDeCredito'];

    /** Nome genérico (outros models têm `utilizacoes()`): só conta em ficheiros que falam dos models protegidos. */
    private const RELACOES_GENERICAS = ['utilizacoes'];

    /** Gravar sem eventos salta os hooks de imutabilidade. */
    private const SEM_EVENTOS = [
        'saveQuietly', 'deleteQuietly', 'updateQuietly', 'forceDeleteQuietly', 'restoreQuietly', 'withoutEvents',
    ];

    private const METODOS_DE_ESCRITA = [
        'fill', 'forceFill', 'update', 'create', 'forceCreate', 'make', 'firstOrCreate', 'firstOrNew', 'updateOrCreate',
        'updateOrInsert', 'setRawAttributes', 'setAttribute', 'increment', 'decrement', 'insert', 'upsert',
    ];

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

    /** @return list<string> */
    private function escritasDoValorUtilizado(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $achados = [];

        for ($i = 0; $i < $total; $i++) {
            // $x->valor_utilizado = …, +=, ++ …
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && ($tokens[$i + 1][1] ?? null) === 'valor_utilizado'
                && in_array($tokens[$i + 2][0] ?? null, ['=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_COALESCE_EQUAL, T_INC, T_DEC], true)) {
                $achados[] = $this->texto($tokens, $i, min($i + 3, $total - 1));

                continue;
            }

            if ($tokens[$i][0] !== T_STRING
                || ! in_array($tokens[$i][1], self::METODOS_DE_ESCRITA, true)
                || ($tokens[$i + 1][0] ?? null) !== '('
                || ! in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $nivel = 0;

            for ($j = $i + 1; $j < $total; $j++) {
                if (in_array($tokens[$j][0], ['(', '[', '{', T_CURLY_OPEN], true)) {
                    $nivel++;
                } elseif (in_array($tokens[$j][0], [')', ']', '}'], true) && --$nivel === 0) {
                    break;
                }

                if ($this->eString($tokens[$j], ['valor_utilizado'])
                    && (($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW || $tokens[$j - 1][0] === '(')) {
                    $achados[] = $this->texto($tokens, $j, min($j + 3, $total - 1));
                }
            }
        }

        return $achados;
    }

    /**
     * Nomes curtos sob os quais o código usa uma classe: ela própria e os aliases de `use … as Alias`.
     *
     * @param list<string> $classes
     * @return list<string>
     */
    private function nomesDe(array $tokens, array $classes): array
    {
        $nomes = $classes;
        $total = count($tokens);

        for ($i = 0; $i < $total - 3; $i++) {
            if ($tokens[$i][0] === T_USE
                && in_array($tokens[$i + 1][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && in_array(substr(strrchr('\\' . $tokens[$i + 1][1], '\\'), 1), $classes, true)
                && $tokens[$i + 2][0] === T_AS
                && $tokens[$i + 3][0] === T_STRING) {
                $nomes[] = $tokens[$i + 3][1];
            }
        }

        return $nomes;
    }

    private function mencionaCreditos(array $tokens): bool
    {
        $nomes = $this->nomesDe($tokens, ['Credito', 'EstadoCredito']);

        foreach ($tokens as $token) {
            if (($token[0] === T_STRING && in_array($token[1], $nomes, true))
                || (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_ends_with($token[1], 'Credito'))
                || $this->eString($token, ['creditos'])) {
                return true;
            }
        }

        return false;
    }

    /** O valor a seguir a `estado =>`, `->estado =` ou `setAttribute('estado',` grava o estado de um crédito? */
    private function valorDeEstadoDoCredito(array $tokens, int $i, array $enums, bool $literalBasta): bool
    {
        if (in_array($tokens[$i][0] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && in_array(substr(strrchr('\\' . $tokens[$i][1], '\\'), 1), $enums, true)
            && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
            && strtolower($tokens[$i + 2][1] ?? '') !== 'class') {
            return true;
        }

        // Um inteiro literal só conta quando é argumento de um método de escrita (nunca em `$attributes`).
        return $literalBasta && ($tokens[$i][0] ?? null) === T_LNUMBER;
    }

    /** @return list<string> */
    private function escritasDoEstadoDoCredito(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $enums = $this->nomesDe($tokens, ['EstadoCredito']);
        $achados = [];
        // Um inteiro literal só se suspeita em ficheiros que falam de créditos (outros models têm o seu `estado`).
        $falaDeCreditos = $this->mencionaCreditos($tokens);

        for ($i = 0; $i < $total; $i++) {
            // ->estado = Enum::X | 3
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && ($tokens[$i + 1][1] ?? null) === 'estado'
                && ($tokens[$i + 2][0] ?? null) === '='
                && $this->valorDeEstadoDoCredito($tokens, $i + 3, $enums, $falaDeCreditos)) {
                $achados[] = $this->texto($tokens, $i, min($i + 4, $total - 1));

                continue;
            }

            if ($tokens[$i][0] !== T_STRING
                || ! in_array($tokens[$i][1], self::METODOS_DE_ESCRITA, true)
                || ($tokens[$i + 1][0] ?? null) !== '('
                || ! in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $nivel = 0;

            for ($j = $i + 1; $j < $total; $j++) {
                if (in_array($tokens[$j][0], ['(', '[', '{', T_CURLY_OPEN], true)) {
                    $nivel++;
                } elseif (in_array($tokens[$j][0], [')', ']', '}'], true) && --$nivel === 0) {
                    break;
                }

                if ($this->eString($tokens[$j], ['estado'])
                    && (($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW || ($tokens[$j - 1][0] === '(' && ($tokens[$j + 1][0] ?? null) === ','))
                    && $this->valorDeEstadoDoCredito($tokens, $j + 2, $enums, $falaDeCreditos)) {
                    $achados[] = $this->texto($tokens, $j, min($j + 3, $total - 1));
                }
            }
        }

        // 'estado' => EstadoCredito::X fora de uma chamada (array atribuído a uma variável e depois passado).
        for ($i = 0; $i < $total; $i++) {
            if ($this->eString($tokens[$i], ['estado'])
                && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_ARROW
                && $this->valorDeEstadoDoCredito($tokens, $i + 2, $enums, false)) {
                $achados[] = $this->texto($tokens, $i, min($i + 4, $total - 1));
            }
        }

        return array_values(array_unique($achados));
    }

    /** O ficheiro fala dos models (ou tabelas) protegidos? Sem isso, `saveQuietly`/`withoutEvents`/`utilizacoes()` são de outros models. */
    private function mencionaModelosProtegidos(array $tokens): bool
    {
        $nomes = $this->nomesDe($tokens, self::MODELS);

        foreach ($tokens as $token) {
            if (($token[0] === T_STRING && in_array($token[1], $nomes, true))
                || (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && in_array(substr(strrchr('\\' . $token[1], '\\'), 1), $nomes, true))
                || $this->eString($token, self::TABELAS)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function escritasEmMassa(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $modelos = $this->nomesDe($tokens, self::MODELS);
        $falaDosModelos = $this->mencionaModelosProtegidos($tokens);
        $achados = [];

        for ($i = 0; $i < $total; $i++) {
            // Escritas sem eventos: saltam os hooks de imutabilidade dos models.
            if ($falaDosModelos
                && $tokens[$i][0] === T_STRING
                && in_array($tokens[$i][1], self::SEM_EVENTOS, true)
                && ($tokens[$i + 1][0] ?? null) === '('
                && in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $achados[] = $this->texto($tokens, max($i - 2, 0), $i + 1);

                continue;
            }

            // Relações dos registos: $pagamento->distribuicoes()->delete(), $credito->utilizacoes()->update(…)
            $relacao = $tokens[$i][0] === T_STRING
                && in_array($tokens[$i][1], self::RELACOES, true)
                && ($falaDosModelos || ! in_array($tokens[$i][1], self::RELACOES_GENERICAS, true))
                && ($tokens[$i + 1][0] ?? null) === '('
                && ($tokens[$i + 2][0] ?? null) === ')'
                && in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

            $modelo = in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && in_array(substr(strrchr('\\' . $tokens[$i][1], '\\'), 1), $modelos, true)
                && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
                && ($tokens[$i - 1][0] ?? null) !== T_NEW
                && ($tokens[$i - 1][0] ?? null) !== T_USE;

            if (! $relacao && ! $modelo && ! $this->eString($tokens[$i], self::TABELAS)) {
                continue;
            }

            for ($j = $i + 1; $j < $total && $tokens[$j][0] !== ';'; $j++) {
                if ($tokens[$j][0] === T_STRING
                    && in_array($tokens[$j][1], self::ESCRITA_EM_MASSA, true)
                    && ($tokens[$j + 1][0] ?? null) === '('
                    && in_array($tokens[$j - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    $achados[] = $this->texto($tokens, $i, $j + 1);

                    break;
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

    public function test_so_o_recalcular_credito_escreve_o_valor_utilizado_e_o_estado_do_credito(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if ($relativo === self::ESCRITOR_DO_CREDITO) {
                continue;
            }

            foreach ([...$this->escritasDoValorUtilizado($codigo), ...$this->escritasDoEstadoDoCredito($codigo)] as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só RecalcularCredito escreve valor_utilizado e o estado de um crédito:\n" . implode("\n", $violacoes));
    }

    public function test_o_escritor_do_credito_existe_e_e_apanhado(): void
    {
        $codigo = file_get_contents($this->raiz() . '/' . self::ESCRITOR_DO_CREDITO);

        $this->assertNotSame([], $this->escritasDoValorUtilizado($codigo));
        $this->assertNotSame([], $this->escritasDoEstadoDoCredito($codigo));
    }

    public function test_os_registos_de_pagamento_nunca_se_escrevem_em_massa(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            foreach ($this->escritasEmMassa($codigo) as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Pagamentos, distribuições, créditos e utilizações só mudam por instância (hooks de imutabilidade):\n" . implode("\n", $violacoes));
    }

    #[DataProvider('exemplosValorUtilizado')]
    public function test_o_varrimento_do_valor_utilizado_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->escritasDoValorUtilizado('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplosValorUtilizado(): array
    {
        return [
            'atribuição' => ['$c->valor_utilizado = 1;', true],
            'composta' => ['$c->valor_utilizado += 1;', true],
            'forceFill' => ["\$c->forceFill(['estado' => 1, 'valor_utilizado' => 0])->save();", true],
            'setAttribute' => ["\$c->setAttribute('valor_utilizado', 1);", true],
            'increment' => ["Credito::query()->increment('valor_utilizado', 5);", true],
            'leitura' => ['$x = $c->valor_utilizado; if ($c->valor_utilizado === $y) {}', false],
            'casts' => ["protected \$casts = ['valor_utilizado' => DinheiroCast::class];", false],
            'array que não grava' => ["\$linha = ['valor_utilizado' => \$c->valor_utilizado];", false],
            'comentário' => ['// $c->valor_utilizado = 1;', false],
        ];
    }

    #[DataProvider('exemplosEstado')]
    public function test_o_varrimento_do_estado_do_credito_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->escritasDoEstadoDoCredito('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplosEstado(): array
    {
        return [
            'forceFill' => ["\$c->forceFill(['estado' => EstadoCredito::ESGOTADO]);", true],
            'atribuição' => ['$c->estado = EstadoCredito::ANULADO;', true],
            'comparação' => ['if ($c->estado === EstadoCredito::ANULADO || $c->estado !== EstadoCredito::DISPONIVEL) {}', false],
            'filtro' => ["Credito::query()->where('estado', EstadoCredito::DISPONIVEL->value);", false],
            'outro enum' => ["\$p->forceFill(['estado' => EstadoPagamento::ANULADO]);", false],
            'cast do model' => ["protected \$casts = ['estado' => EstadoCredito::class];", false],
            'setAttribute com enum' => ["\$c->setAttribute('estado', EstadoCredito::ESGOTADO);", true],
            'setAttribute literal' => ["\$c = Credito::find(1); \$c->setAttribute('estado', 2);", true],
            'literal num forceFill' => ["\$c = Credito::find(1); \$c->forceFill(['estado' => 3])->save();", true],
            'literal na atribuição' => ['$c = Credito::find(1); $c->estado = 3;', true],
            'literal num update' => ["\$c = Credito::find(1); \$c->update(['estado' => 2]);", true],
            'alias do enum' => ["use Modules\\Financeiro\\Enums\\EstadoCredito as EC;\n\$c->forceFill(['estado' => EC::ESGOTADO]);", true],
            'alias de outro enum' => ["use Modules\\Financeiro\\Enums\\EstadoPagamento as EP;\n\$p->forceFill(['estado' => EP::ANULADO]);", false],
            'literal noutro model (sem Credito no ficheiro)' => ["\$curso->forceFill(['estado' => 1])->save(); User::create(['estado' => 0]);", false],
            'literal em $attributes' => ["protected \$attributes = ['estado' => 1];", false],
            'setAttribute de outro campo' => ["\$c->setAttribute('observacao', 'x');", false],
            'literal noutro campo' => ["\$c->forceFill(['prioridade' => 3]);", false],
        ];
    }

    #[DataProvider('exemplosEmMassa')]
    public function test_o_varrimento_de_escritas_em_massa_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->escritasEmMassa('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplosEmMassa(): array
    {
        return [
            'update em query' => ["Pagamento::query()->whereKey(1)->update(['estado' => 2]);", true],
            'delete em query' => ["PagamentoPropina::query()->where('pagamento_id', 1)->delete();", true],
            'increment' => ["Credito::query()->whereKey(1)->increment('valor_utilizado');", true],
            'insert estático' => ['CreditoUtilizacao::insert($linhas);', true],
            'destroy' => ['Pagamento::destroy(1);', true],
            'tabela' => ["DB::table('pagamento_propinas')->delete();", true],
            'FQN' => ['\\Modules\\Financeiro\\Models\\Credito::query()->update([]);', true],
            'create' => ["PagamentoPropina::create(['valor' => 1]);", false],
            'leitura' => ["Pagamento::query()->where('estado', 1)->exists(); Credito::query()->sum('valor');", false],
            'relação' => ['return $this->hasMany(PagamentoPropina::class, "pagamento_id")->orderBy("id");', false],
            'instância' => ["\$pagamento->forceFill(['estado' => 2])->save();", false],
            'relação distribuicoes delete' => ['$pagamento->distribuicoes()->delete();', true],
            'relação utilizacoes update' => ["use Modules\\Financeiro\\Models\\Pagamento;\n\$credito->utilizacoes()->update(['estado' => 2]);", true],
            'relação utilizacoes com filtro' => ["use Modules\\Financeiro\\Models\\Pagamento;\n\$credito->utilizacoes()->where('estado', 1)->update(['valor' => 1]);", true],
            'relação utilizacoesCombinadas' => ['$pagamento->utilizacoesCombinadas()->delete();', true],
            'relação utilizacoesDeCredito' => ['$aluno->utilizacoesDeCredito()->delete();', true],
            'relação nullsafe' => ['$pagamento?->distribuicoes()->delete();', true],
            'relação só lida' => ['$pagamento->distribuicoes()->get(); $credito->utilizacoes()->sum("valor");', false],
            'relação create' => ["\$pagamento->distribuicoes()->create(['propina_id' => 1]);", false],
            'relação como propriedade' => ['$pagamento->distribuicoes->each->save(); $x = $c->utilizacoes->delete;', false],
            'saveQuietly' => ["use Modules\\Financeiro\\Models\\Pagamento;\n\$pagamento->saveQuietly();", true],
            'deleteQuietly' => ["use Modules\\Financeiro\\Models\\Pagamento;\n\$distribuicao->deleteQuietly();", true],
            'updateQuietly na instância' => ["use Modules\\Financeiro\\Models\\Pagamento;\n\$credito->updateQuietly(['estado' => 2]);", true],
            'withoutEvents do model' => ['Pagamento::withoutEvents(fn () => $p->save());', true],
            'withoutEvents genérico' => ["use Modules\\Financeiro\\Models\\Pagamento;\nModel::withoutEvents(function () {});", true],
            'withoutEvents por alias' => ["use Modules\\Financeiro\\Models\\Pagamento as P;\nP::withoutEvents(fn () => 1);", true],
            'saveQuietly sem models protegidos' => ['$utilizador->saveQuietly(); $x->deleteQuietly(); $y->updateQuietly([]);', false],
            'withoutEvents sem models protegidos' => ["use Modules\\Core\\Models\\User;\nUser::withoutEvents(fn () => 1);", false],
            'relação utilizacoes sem models protegidos' => ['$plano->utilizacoes()->delete(); $x->utilizacoes()->update([]);', false],
            'saveQuietly com tabela protegida' => ["\$x->saveQuietly(); \$t = 'pagamentos';", true],
            'save normal' => ['$pagamento->save(); $credito->saveOrFail();', false],
            'alias de model' => ["use Modules\\Financeiro\\Models\\Credito as C;\nC::query()->update([]);", true],
            'alias de outro model' => ["use Modules\\Financeiro\\Models\\Propina as C;\nC::query()->update([]);", false],
            'exists rule' => ["Rule::exists('pagamentos', 'id')->where(fn (\$q) => \$q->where('tenant_id', 1));", false],
        ];
    }
}
