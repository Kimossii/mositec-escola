<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ponto único de criação de propinas (§1.2 do plano de fases): só o GerarPropinasService cria `Propina`.
 * É lá que vivem a elegibilidade, a resolução por competência, o bloqueio da matrícula e dos planos e a
 * verificação de sobreposição; um `Propina::create` noutro sítio contornaria tudo isso. Varre `app`, `routes`
 * e os seeders (raiz e módulos), sem comentários. Não apanha criação por nome de classe dinâmico nem
 * `replicate()`; é uma rede de segurança, não uma prova formal.
 */
class CriadoresDePropinaTest extends TestCase
{
    // PropinaVirtual: objecto em memória para estimar multas na pré-visualização; nunca se grava.
    private const CRIADORES_PERMITIDOS = ['Modules/Financeiro/app/Services/GerarPropinasService.php', 'Modules/Financeiro/app/Support/PropinaVirtual.php'];

    private const PADROES = [
        '/\bPropina::(?:query\(\)\s*->\s*)?(?:create\w*|forceCreate\w*|firstOrCreate|updateOrCreate|firstOrNew|make\w*|insert\w*|upsert)\s*\(/',
        '/\bnew\s+\\\\?(?:Modules\\\\Financeiro\\\\Models\\\\)?Propina\s*\(/',
        '/->\s*propinas\s*\(\s*\)\s*->\s*(?:create\w*|forceCreate\w*|firstOrCreate|updateOrCreate|firstOrNew|make\w*|save\w*)\s*\(/',
    ];

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    private function semComentarios(string $codigo): string
    {
        $saida = '';

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $saida .= is_array($token) ? $token[1] : $token;
        }

        return $saida;
    }

    /** @return list<string> */
    private function criacoes(string $codigo): array
    {
        $achados = [];
        $limpo = $this->semComentarios($codigo);

        foreach (self::PADROES as $padrao) {
            if (preg_match_all($padrao, $limpo, $encontrados) > 0) {
                array_push($achados, ...$encontrados[0]);
            }
        }

        return $achados;
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

    public function test_so_o_gerador_cria_propinas(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if (in_array($relativo, self::CRIADORES_PERMITIDOS, true)) {
                continue;
            }

            foreach ($this->criacoes($codigo) as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só GerarPropinasService cria propinas:\n" . implode("\n", $violacoes));
    }

    public function test_o_criador_permitido_existe_e_e_apanhado_pelo_varrimento(): void
    {
        $caminho = $this->raiz() . '/' . self::CRIADORES_PERMITIDOS[0];

        $this->assertFileExists($caminho);
        $this->assertNotSame([], $this->criacoes(file_get_contents($caminho)));
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $criacao): void
    {
        $this->assertSame($criacao, $this->criacoes('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'create' => ["Propina::create(['matricula_id' => 1]);", true],
            'query()->create' => ['Propina::query()->create([]);', true],
            'forceCreate' => ['Propina::forceCreate([]);', true],
            'firstOrCreate' => ['Propina::firstOrCreate([]);', true],
            'insert' => ['Propina::insert([]);', true],
            'new Propina' => ['$p = new Propina();', true],
            'new Propina com FQN' => ['$p = new \Modules\Financeiro\Models\Propina([]);', true],
            'relação propinas()->create' => ['$plano->propinas()->create([]);', true],
            'relação propinas()->save' => ['$m->propinas()->save($p);', true],
            'leitura' => ["Propina::query()->where('id', 1)->first();", false],
            'PlanoPropina::create' => ['PlanoPropina::create([]);', false],
            'contar propinas do plano' => ['$plano->propinas()->count();', false],
            'new PropinaAjuste' => ['$a = new PropinaAjuste();', false],
            'cancelamento não é criação' => ["\$p->forceFill(['estado' => EstadoCobranca::CANCELADA])->save();", false],
            'comentário' => ["// Propina::create([]);\n/* new Propina(); */", false],
        ];
    }
}
