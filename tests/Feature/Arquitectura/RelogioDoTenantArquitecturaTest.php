<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * O "hoje" de negócio do Financeiro é o da escola: vem sempre de RelogioDoTenant. Varre, por tokens,
 * Modules/Financeiro/app à procura de `now()`, `today()`, `Carbon::now/today`, `CarbonImmutable::now/today`,
 * `new DateTime(Immutable)` sem argumentos e da regra de validação `today`/`tomorrow`/`yesterday`
 * (`before_or_equal:today`…), que usam UTC.
 *
 * Excepções justificadas vão em EXCEPCOES (ficheiro => razão). Não apanha: `date('Y-m-d')`, `time()`,
 * `strtotime`, SQL com CURRENT_DATE e datas montadas por variáveis; é uma rede de segurança.
 */
class RelogioDoTenantArquitecturaTest extends TestCase
{
    /** Ficheiro => razão. CambioPlataformaCommand corre sem tenant: usa a constante do fuso por omissão. */
    private const EXCEPCOES = [];

    private const CLASSES_CARBON = ['Carbon', 'CarbonImmutable'];

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> */
    private function codigo(): array
    {
        $ficheiros = [];
        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->raiz() . '/Modules/Financeiro/app', FilesystemIterator::SKIP_DOTS));

        foreach ($iterador as $ficheiro) {
            if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php') {
                $ficheiros[str_replace($this->raiz() . '/', '', $ficheiro->getPathname())] = file_get_contents($ficheiro->getPathname());
            }
        }

        return $ficheiros;
    }

    /** @return list<string> */
    private function infraccoes(string $codigo): array
    {
        $tokens = [];

        foreach (token_get_all($codigo) as $t) {
            $t = is_array($t) ? [$t[0], $t[1]] : [$t, $t];

            if (! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = $t;
            }
        }

        $achados = [];
        $total = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            [$tipo, $texto] = $tokens[$i];
            $seguinte = $tokens[$i + 1][0] ?? null;
            $anterior = $tokens[$i - 1][0] ?? null;

            // now() / today() livres ou como método estático/de instância
            if ($tipo === T_STRING && in_array(strtolower($texto), ['now', 'today'], true) && $seguinte === '(' && $anterior !== T_FUNCTION) {
                $achados[] = $texto . '()';

                continue;
            }

            // new DateTime() / new DateTimeImmutable() sem data
            if ($tipo === T_NEW && in_array(ltrim($tokens[$i + 1][1] ?? '', '\\'), ['DateTime', 'DateTimeImmutable'], true)
                && ($tokens[$i + 2][0] ?? null) === '(' && ($tokens[$i + 3][0] ?? null) === ')') {
                $achados[] = 'new ' . $tokens[$i + 1][1] . '()';
            }

            // 'before_or_equal:today' e afins
            if ($tipo === T_CONSTANT_ENCAPSED_STRING && preg_match('/:(today|tomorrow|yesterday|now)\b/', $texto)) {
                $achados[] = $texto;
            }

            // 'today' / 'now' como argumento de regras em arrays: ['before_or_equal', 'today'] não é usado aqui
        }

        return array_values(array_unique($achados));
    }

    public function test_ha_codigo_para_analisar(): void
    {
        $this->assertArrayHasKey('Modules/Financeiro/app/Support/ContribuicaoDePagamento.php', $this->codigo());
    }

    public function test_o_financeiro_nao_calcula_o_hoje_de_negocio_sem_o_relogio_do_tenant(): void
    {
        $violacoes = [];

        foreach ($this->codigo() as $relativo => $codigo) {
            if (array_key_exists($relativo, self::EXCEPCOES)) {
                continue;
            }

            foreach ($this->infraccoes($codigo) as $achado) {
                $violacoes[] = "{$relativo}: {$achado}";
            }
        }

        $this->assertSame([], $violacoes, "Usa RelogioDoTenant::hoje()/agora() em vez de now()/today():\n" . implode("\n", $violacoes));
    }

    public function test_as_excepcoes_existem_e_tem_razao(): void
    {
        $this->assertIsArray(self::EXCEPCOES);

        foreach (self::EXCEPCOES as $ficheiro => $razao) {
            $this->assertFileExists($this->raiz() . '/' . $ficheiro);
            $this->assertNotSame('', trim($razao));
        }
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $infraccao): void
    {
        $this->assertSame($infraccao, $this->infraccoes('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'now()' => ['$a = now();', true],
            'today()' => ['$a = today();', true],
            'Carbon::now' => ['$a = Carbon::now();', true],
            'Carbon::today' => ['$a = \Carbon\Carbon::today();', true],
            'CarbonImmutable::now' => ['$a = CarbonImmutable::now()->toDateString();', true],
            'CarbonImmutable::today' => ['$a = CarbonImmutable::today();', true],
            'Date::now' => ['$a = Date::now();', true],
            'new DateTime sem argumentos' => ['$a = new \DateTimeImmutable();', true],
            'regra before_or_equal:today' => ["\$r = ['before_or_equal:today'];", true],
            'regra com tomorrow' => ["\$r = 'after:tomorrow';", true],
            'relógio do tenant' => ['$a = app(RelogioDoTenant::class)->hoje();', false],
            'parse de data' => ["\$a = CarbonImmutable::parse('2026-01-01');", false],
            'createFromFormat' => ["\$a = CarbonImmutable::createFromFormat('!Y-m-d', \$v);", false],
            'método chamado now numa classe própria' => ['function now() {}', false],
            'comentário' => ['// now() e today()', false],
            'texto sem now()' => ["\$a = 'hoje';", false],
        ];
    }
}
