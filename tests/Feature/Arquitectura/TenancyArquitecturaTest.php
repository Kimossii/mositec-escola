<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regras de dependência e de isolamento verificadas por leitura dos ficheiros.
 * Analisa código de aplicação, rotas e seeders (da raiz e de cada módulo).
 * Os testes e as migrations ficam de fora.
 */
class TenancyArquitecturaTest extends TestCase
{
    /**
     * Gerador único de sequências por tenant: filtra por tenant_id explicitamente
     * no upsert e lê o resto pelo model.
     */
    private const EXCEPCOES_DB_TABLE = [
        'Modules/Core/app/Services/GeradorSequencia.php',
    ];

    /**
     * Escritas em massa que o Eloquent não protege (insert/upsert não passam pelos eventos de
     * PertenceAoTenant, e os métodos "Quietly" e withoutEvents desligam-nos). Só estas excepções:
     *  - as duas sincronizações de permissões constroem as linhas com o tenant_id do contexto
     *    (provado em PermissaoTenancyTest::test_sincronizar_permissoes_grava_tenant_id_nas_escritas_em_massa);
     *  - o gerador de sequências faz upsert com o tenant_id do contexto.
     * Qualquer outra ocorrência é violação.
     */
    private const EXCEPCOES_ELOQUENT = [
        'Modules/Permissao/app/Actions/SincronizarPermissoesPerfilAction.php',
        'Modules/Permissao/app/Actions/SincronizarPermissoesUtilizadorAction.php',
        'Modules/Core/app/Services/GeradorSequencia.php',
    ];

    /**
     * Formas de contornar a trait PertenceAoTenant (e de aceder à BD fora do Eloquent).
     *
     * @return string[] excertos em violação
     */
    private function violacoesDeBypass(string $codigo): array
    {
        $padroes = [
            '/\bwithoutEvents\s*\(/',
            '/\b(?:save|update|delete|create|forceCreate)Quietly\s*\(/',
            '/forceFill\s*\(\s*\[[^\]]*tenant_id/',
            '/->\s*update\s*\(\s*\[[^\]]*tenant_id/',
            '/(?:::|->)\s*(?:insert|insertOrIgnore|insertGetId|insertUsing|upsert)\s*\(/',
            '/\bapp\s*\(\s*[\'"]db[\'"]\s*\)/',
            '/->\s*getConnection\s*\(\s*\)\s*->\s*(?:table|select|insert|update|delete|statement|unprepared)\s*\(/',
            '/\bDB::raw\s*\(/',
        ];
        $violacoes = [];

        foreach ($padroes as $padrao) {
            if (preg_match_all($padrao, $codigo, $achados) > 0) {
                array_push($violacoes, ...$achados[0]);
            }
        }

        return $violacoes;
    }

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> caminho relativo => conteúdo */
    private function codigoDeAplicacao(): array
    {
        $pastas = array_filter([
            $this->raiz() . '/app',
            $this->raiz() . '/routes',
            $this->raiz() . '/database/seeders',
            ...glob($this->raiz() . '/Modules/*/app'),
            ...glob($this->raiz() . '/Modules/*/routes'),
            ...glob($this->raiz() . '/Modules/*/database/seeders'),
        ], 'is_dir');
        $ficheiros = [];

        foreach ($pastas as $pasta) {
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php') {
                    $relativo = str_replace($this->raiz() . '/', '', $ficheiro->getPathname());
                    $ficheiros[$relativo] = file_get_contents($ficheiro->getPathname());
                }
            }
        }

        return $ficheiros;
    }

    /**
     * @param  string[]  $prefixosPermitidos
     * @return string[] caminhos onde o padrão aparece fora dos prefixos permitidos
     */
    private function ocorrencias(string $padrao, array $prefixosPermitidos): array
    {
        $encontrados = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            foreach ($prefixosPermitidos as $prefixo) {
                if (str_starts_with($caminho, $prefixo)) {
                    continue 2;
                }
            }

            if (preg_match($padrao, $conteudo) === 1) {
                $encontrados[] = $caminho;
            }
        }

        return $encontrados;
    }

    public function test_ha_codigo_de_aplicacao_para_analisar(): void
    {
        $caminhos = array_keys($this->codigoDeAplicacao());

        $this->assertContains('Modules/Core/app/Tenancy/TenantContext.php', $caminhos);
        $this->assertContains('Modules/Tenant/app/Models/Tenant.php', $caminhos);
    }

    public function test_so_o_modulo_tenant_conhece_o_modulo_tenant(): void
    {
        // O seeder raiz compõe os seeders de todos os módulos; é o único ponto
        // fora do módulo Tenant que o pode referir.
        $violacoes = $this->ocorrencias('/Modules\\\\Tenant\\\\/', ['Modules/Tenant/', 'database/seeders/DatabaseSeeder.php']);

        $this->assertSame([], $violacoes, "Estes ficheiros importam Modules\\Tenant. Os módulos da escola e o Core só podem conhecer TenantAtual e TenantContext:\n" . implode("\n", $violacoes));
    }

    public function test_ninguem_remove_os_global_scopes(): void
    {
        $violacoes = $this->ocorrencias('/withoutGlobalScopes?(Except)?\s*\(|newQueryWithoutScopes?\s*\(|newModelQuery\s*\(/', ['Modules/Core/app/Tenancy/']);

        $this->assertSame([], $violacoes, "withoutGlobalScope(s), newQueryWithoutScope(s) e newModelQuery ignoram o isolamento por tenant:\n" . implode("\n", $violacoes));
    }

    public function test_o_tenant_scope_so_e_referido_no_runtime_de_tenancy(): void
    {
        $violacoes = $this->ocorrencias('/\bTenantScope\b/', ['Modules/Core/app/Tenancy/']);

        $this->assertSame([], $violacoes, "TenantScope só é usado pela trait PertenceAoTenant:\n" . implode("\n", $violacoes));
    }

    /**
     * O Core não depende de módulos de negócio. Excepção pré-existente e anterior à
     * tenancy: Horario referencia o User (relação), não é do âmbito das sequências.
     */
    public function test_o_core_nao_importa_modulos_de_negocio(): void
    {
        $permitidos = ['Modules/Core/app/Models/Horario.php'];
        $violacoes = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            if (! str_starts_with($caminho, 'Modules/Core/app/') || in_array($caminho, $permitidos, true)) {
                continue;
            }

            if (preg_match('/^use\s+Modules\\\\(?!Core\\\\)/m', $conteudo)) {
                $violacoes[] = $caminho;
            }
        }

        $this->assertSame([], $violacoes, "O Core não pode importar módulos de negócio:\n" . implode("\n", $violacoes));
    }

    public function test_db_table_so_e_usado_nas_excepcoes_declaradas(): void
    {
        $violacoes = $this->ocorrencias('/\bDB::(table|select|selectOne|insert|update|delete|statement|unprepared|query|connection|raw)\s*\(|\bapp\s*\(\s*[\'"]db[\'"]\s*\)|->\s*getConnection\s*\(\s*\)\s*->\s*table\s*\(/', self::EXCEPCOES_DB_TABLE);

        $this->assertSame([], $violacoes, "As consultas directas pela fachada DB ignoram o isolamento por tenant. Use o model (com PertenceAoTenant):\n" . implode("\n", $violacoes));
    }

    public function test_ninguem_contorna_o_invariante_de_tenant_id_pelo_eloquent(): void
    {
        $violacoes = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            if (in_array($caminho, self::EXCEPCOES_ELOQUENT, true) || str_starts_with($caminho, 'Modules/Core/app/Tenancy/')) {
                continue;
            }

            foreach ($this->violacoesDeBypass($conteudo) as $excerto) {
                $violacoes[] = "{$caminho}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Escritas em massa, métodos Quietly, withoutEvents ou acesso directo à ligação ignoram PertenceAoTenant. Use create()/save() num model, ou declare uma excepção explícita e comentada:\n" . implode("\n", $violacoes));
    }

    /** @dataProvider exemplosDeBypass */
    public function test_o_varrimento_de_bypass_apanha_o_que_deve(string $codigo, bool $violacao): void
    {
        $this->assertSame($violacao, $this->violacoesDeBypass($codigo) !== [], $codigo);
    }

    public static function exemplosDeBypass(): array
    {
        return [
            'withoutEvents' => ['Curso::withoutEvents(fn () => $c->save());', true],
            'saveQuietly' => ['$curso->saveQuietly();', true],
            'updateQuietly' => ['$curso->updateQuietly([\'nome\' => 1]);', true],
            'deleteQuietly' => ['$curso->deleteQuietly();', true],
            'createQuietly' => ['Curso::createQuietly([]);', true],
            'forceFill com tenant_id' => ["\$m->forceFill(['tenant_id' => 2])->save();", true],
            'update com tenant_id' => ["Curso::whereKey(1)->update(['tenant_id' => 2]);", true],
            'insert estático' => ['Curso::insert($linhas);', true],
            'insert na query' => ['Curso::query()->insert($linhas);', true],
            'upsert' => ["Curso::upsert(\$linhas, ['codigo']);", true],
            'insertOrIgnore' => ['Curso::insertOrIgnore($l);', true],
            'insertGetId' => ['$q->insertGetId($l);', true],
            'app db' => ["app('db')->table('x');", true],
            'getConnection table' => ["\$m->getConnection()->table('x')->get();", true],
            'DB raw' => ["DB::raw('1');", true],
            'save normal' => ['$curso->save(); Curso::create([]);', false],
            'update sem tenant_id' => ["\$curso->update(['nome' => 'x']);", false],
            'forceFill sem tenant_id' => ["\$m->forceFill(['configurado_em' => now()])->save();", false],
            'getConnection driver' => ["\$this->getConnection()->getDriverName();", false],
            'transaction' => ['DB::transaction(fn () => 1);', false],
        ];
    }

    public function test_a_cache_so_e_usada_nas_excepcoes_declaradas(): void
    {
        $violacoes = $this->ocorrencias('/\bCache::|\bcache\s*\(/', ['Modules/Core/app/Tenancy/']);

        $this->assertSame([], $violacoes, "A cache partilhada não é isolada por tenant:\n" . implode("\n", $violacoes));
    }

    public function test_so_o_runtime_de_tenancy_define_ou_limpa_o_contexto(): void
    {
        $violacoes = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            if (str_starts_with($caminho, 'Modules/Core/app/Tenancy/')) {
                continue;
            }

            if (str_contains($conteudo, 'TenantContext') && preg_match('/->\s*(definir|limpar)\s*\(/', $conteudo) === 1) {
                $violacoes[] = $caminho;
            }
        }

        $this->assertSame([], $violacoes, "Fora do runtime de tenancy, o único caminho para ter contexto é TenantContext::executarComo():\n" . implode("\n", $violacoes));
    }

    private const ARG_CAMINHO_TENANT = '\s*CaminhoTenant::para\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)\s*,';

    /**
     * Procura gravações de ficheiros cujo 1.º argumento não seja exactamente
     * `CaminhoTenant::para(...)` seguido de vírgula, e usos de CaminhoTenant::para()
     * concatenado (`para('a') . '/../2/x'`) ou de CaminhoTenant::prefixo() (o Flysystem
     * normaliza `..` e escreveria no prefixo de outro tenant).
     *
     * Apanha: `Storage::put(...)` com ou sem disk(), `$disco->put(...)` quando
     * `$disco = Storage::disk(...)`, e `->store/storeAs/storePublicly(As)/putFile(As)/
     * writeStream/move(...)` sobre qualquer objecto.
     *
     * Fragilidade residual: um caminho construído numa variável antes da chamada
     * (`$c = CaminhoTenant::para('a'); ...->store($c)`) é recusado por não ser literal
     * `CaminhoTenant::para(...)`, mas um `$c` calculado de outra forma e passado a um método
     * que o varrimento não conhece não é visto. Por isso o comportamento também está provado
     * nos testes de cada módulo e CaminhoTenant::garantir() protege leituras e remoções.
     *
     * @return string[] excertos em violação
     */
    private function violacoesDeFicheiros(string $codigo): array
    {
        $violacoes = [];
        $metodosObjecto = 'store|storeAs|storePublicly|storePubliclyAs|putFile|putFileAs|writeStream|move';
        $metodosDisco = 'put|append|prepend|copy|putFile|putFileAs|writeStream|move';

        $padroes = [
            '/->\s*(?:' . $metodosObjecto . ')\s*\(/',
            '/Storage::\s*(?:disk\s*\([^)]*\)\s*->\s*)?(?:' . $metodosDisco . ')\s*\(/',
        ];

        if (preg_match_all('/\$(\w+)\s*=\s*Storage::\s*disk\s*\(/', $codigo, $variaveis) > 0) {
            foreach (array_unique($variaveis[1]) as $variavel) {
                $padroes[] = '/\$' . $variavel . '\s*->\s*(?:' . $metodosDisco . ')\s*\(/';
            }
        }

        foreach ($padroes as $padrao) {
            preg_match_all($padrao, $codigo, $achados, PREG_OFFSET_CAPTURE);

            foreach ($achados[0] as [$texto, $posicao]) {
                $resto = substr($codigo, $posicao + strlen($texto));

                if (preg_match('/^' . self::ARG_CAMINHO_TENANT . '/', $resto) !== 1) {
                    $violacoes[] = trim($texto . ' ' . substr($resto, 0, 40));
                }
            }
        }

        if (preg_match_all('/CaminhoTenant::para\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)\s*\./', $codigo, $concatenados) > 0) {
            array_push($violacoes, ...$concatenados[0]);
        }

        if (preg_match_all('/CaminhoTenant::prefixo\s*\(/', $codigo, $prefixos) > 0) {
            array_push($violacoes, ...$prefixos[0]);
        }

        return $violacoes;
    }

    public function test_as_gravacoes_de_ficheiros_usam_caminho_tenant(): void
    {
        $violacoes = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            if (str_starts_with($caminho, 'Modules/Core/app/Tenancy/')) {
                continue;
            }

            foreach ($this->violacoesDeFicheiros($conteudo) as $excerto) {
                $violacoes[] = "{$caminho}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Gravações de ficheiros sem CaminhoTenant::para(...) como primeiro argumento (ou com o caminho concatenado / CaminhoTenant::prefixo() fora do runtime de tenancy):\n" . implode("\n", $violacoes));
    }

    /** @dataProvider exemplosDeFicheiros */
    public function test_o_varrimento_de_ficheiros_apanha_o_que_deve(string $codigo, bool $violacao): void
    {
        $this->assertSame($violacao, $this->violacoesDeFicheiros($codigo) !== [], $codigo);
    }

    public static function exemplosDeFicheiros(): array
    {
        return [
            'store correcto' => ["\$f->store(CaminhoTenant::para('a/b'), 'public');", false],
            'store com concatenação dentro de para' => ["\$f->store(CaminhoTenant::para('a/' . \$p->id), 'x');", false],
            'putFile correcto' => ["Storage::disk('x')->putFile(CaminhoTenant::para('a'), \$f);", false],
            'put correcto' => ["Storage::disk('x')->put(CaminhoTenant::para('a') , 'c');", false],
            'sem escrita' => ["Storage::disk('x')->delete(\$m->caminho); \$c->put('k', 1);", false],
            'store literal' => ["\$f->store('a/b', 'public');", true],
            'fachada sem disk' => ["Storage::put('x', 'c');", true],
            'fachada sem disk, correcto' => ["Storage::put(CaminhoTenant::para('x'), 'c');", false],
            'fachada com disk' => ["Storage::disk('x')->put('x', 'c');", true],
            'variável de disco' => ["\$d = Storage::disk('x'); \$d->put('x', 'c');", true],
            'variável de disco, correcto' => ["\$d = Storage::disk('x'); \$d->put(CaminhoTenant::para('x'), 'c');", false],
            'concatenado com ..' => ["\$f->store(CaminhoTenant::para('a') . '/../../2/x', 'p');", true],
            'concatenado sem espaço' => ["\$f->store(CaminhoTenant::para('a').'/../2/x', 'p');", true],
            'prefixo' => ["\$f->store(CaminhoTenant::prefixo() . '../2/x', 'p');", true],
            'move para fora' => ["\$f->move(storage_path('app/x'), 'n');", true],
            'move correcto' => ["\$f->move(CaminhoTenant::para('a'), 'n');", false],
            'putFileAs literal' => ["\$d->putFileAs('a', \$f, 'n');", true],
            'writeStream literal' => ["\$d->writeStream('a', \$s);", true],
            'storeAs literal' => ["\$f->storeAs('a', 'n', 'public');", true],
        ];
    }

    public function test_nenhum_ficheiro_e_lido_ou_apagado_por_caminho_literal(): void
    {
        // O caminho vem sempre do model (com scope), nunca de um literal nem do cliente.
        $violacoes = $this->ocorrencias('/Storage::disk\([^)]*\)\s*->\s*(?:delete|url|path|download|response|get|exists|mimeType|size)\s*\(\s*[\'"]/', []);

        $this->assertSame([], $violacoes, "Leitura/remoção de ficheiros por caminho literal:\n" . implode("\n", $violacoes));
    }

    /**
     * Classes de aplicação que entram em filas ou na linha de comandos correm sem pedido HTTP,
     * logo sem tenant: têm de declarar como o obtêm. Varrimento por texto (sem analisar quais
     * models tocam): qualquer classe assim, fora destas excepções, tem de usar a trait. Os
     * comandos do módulo Tenant gerem `tenants` e `domains`, que não são tenant-scoped.
     */
    private const EXCEPCOES_FILAS_E_COMANDOS = [];

    private function declaraClasse(string $conteudo, string $padraoHerancaOuInterface): bool
    {
        return preg_match('/^(?:abstract\s+|final\s+)?class\s+\w+[^{]*\b' . $padraoHerancaOuInterface . '\b/m', $conteudo) === 1;
    }

    public function test_jobs_enfileirados_usam_com_tenant(): void
    {
        $violacoes = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            if (! str_starts_with($caminho, 'Modules/') || in_array($caminho, self::EXCEPCOES_FILAS_E_COMANDOS, true)) {
                continue;
            }

            if ($this->declaraClasse($conteudo, 'ShouldQueue(?:AfterCommit)?') && ! preg_match('/^[ \t]+use\s+[^;]*\bComTenant\b/m', $conteudo)) {
                $violacoes[] = $caminho;
            }
        }

        $this->assertSame([], $violacoes, "Classes ShouldQueue sem a trait ComTenant (correriam sem tenant):\n" . implode("\n", $violacoes));
    }

    /**
     * Job único com ComTenant sem UnicoPorTenant (o lock seria partilhado entre escolas), ou que,
     * mesmo com a trait, define o seu próprio uniqueId() (que sobrepõe o da trait e perde o tenant).
     */
    private function jobUnicoSemUnicoPorTenant(string $conteudo): bool
    {
        if (! $this->declaraClasse($conteudo, 'ShouldBeUnique(?:UntilProcessing)?')
            || preg_match('/^[ \t]+use\s+[^;]*\bComTenant\b/m', $conteudo) !== 1) {
            return false;
        }

        $usaTrait = preg_match('/^[ \t]+use\s+[^;]*\bUnicoPorTenant\b/m', $conteudo) === 1;
        $defineUniqueId = preg_match('/function\s+uniqueId\s*\(/', $conteudo) === 1;

        return ! $usaTrait || $defineUniqueId;
    }

    public function test_jobs_unicos_usam_unico_por_tenant(): void
    {
        $violacoes = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            if (str_starts_with($caminho, 'Modules/') && $this->jobUnicoSemUnicoPorTenant($conteudo)) {
                $violacoes[] = $caminho;
            }
        }

        $this->assertSame([], $violacoes, "Jobs ShouldBeUnique com ComTenant sem a trait UnicoPorTenant (o lock seria partilhado entre escolas):\n" . implode("\n", $violacoes));
    }

    /** @dataProvider exemplosDeJobsUnicos */
    public function test_o_varrimento_de_jobs_unicos_apanha_o_que_deve(string $codigo, bool $violacao): void
    {
        $this->assertSame($violacao, $this->jobUnicoSemUnicoPorTenant($codigo), $codigo);
    }

    public static function exemplosDeJobsUnicos(): array
    {
        return [
            'único sem a trait' => ["class J implements ShouldBeUnique, ShouldQueue\n{\n    use Queueable, ComTenant;\n}", true],
            'único até processar sem a trait' => ["class J implements ShouldQueue, ShouldBeUniqueUntilProcessing\n{\n    use ComTenant;\n}", true],
            'único com a trait' => ["class J implements ShouldBeUnique, ShouldQueue\n{\n    use ComTenant, UnicoPorTenant;\n}", false],
            'único com a trait em linha própria' => ["class J implements ShouldBeUnique\n{\n    use ComTenant;\n    use UnicoPorTenant;\n}", false],
            'não único' => ["class J implements ShouldQueue\n{\n    use ComTenant;\n}", false],
            'único com uniqueId próprio e sem a trait' => ["class J implements ShouldBeUnique\n{\n    use ComTenant;\n    public function uniqueId(): string { return 'x'; }\n}", true],
            'único com a trait mas uniqueId próprio' => ["class J implements ShouldBeUnique\n{\n    use ComTenant, UnicoPorTenant;\n    public function uniqueId(): string { return 'x'; }\n}", true],
            'único com a trait e identificadorUnico' => ["class J implements ShouldBeUnique\n{\n    use ComTenant, UnicoPorTenant;\n    protected function identificadorUnico(): string { return 'x'; }\n}", false],
        ];
    }

    public function test_comandos_de_dados_de_escola_usam_a_convencao_de_tenant(): void
    {
        $violacoes = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            if (! str_starts_with($caminho, 'Modules/') || str_starts_with($caminho, 'Modules/Tenant/') || in_array($caminho, self::EXCEPCOES_FILAS_E_COMANDOS, true)) {
                continue;
            }

            if ($this->declaraClasse($conteudo, '(?:Symfony)?Command') && ! preg_match('/^[ \t]+use\s+(?:ParaTodosOsTenants|EscolheUmTenant)\b/m', $conteudo)) {
                $violacoes[] = $caminho;
            }
        }

        $this->assertSame([], $violacoes, "Comandos fora do módulo Tenant sem ParaTodosOsTenants/EscolheUmTenant (--tenant/--todos):\n" . implode("\n", $violacoes));
    }

    /** @dataProvider exemplosDeClasses */
    public function test_o_varrimento_de_classes_apanha_o_que_deve(string $codigo, string $padrao, bool $esperado): void
    {
        $this->assertSame($esperado, $this->declaraClasse($codigo, $padrao), $codigo);
    }

    public static function exemplosDeClasses(): array
    {
        return [
            'job' => ['class Foo implements ShouldQueue', 'ShouldQueue', true],
            'job com outras interfaces' => ['final class Foo extends Bar implements Baz, ShouldQueue {', 'ShouldQueue', true],
            'job em várias linhas' => ["class Foo\n    implements ShouldQueue\n{", 'ShouldQueue', true],
            'classe normal' => ['class Foo extends Bar', 'ShouldQueue', false],
            'comando' => ['class FooCommand extends Command', 'Command', true],
            'comando abstracto' => ['abstract class Foo extends Command', 'Command', true],
            'job após commit' => ['class Foo implements ShouldQueueAfterCommit', 'ShouldQueue(?:AfterCommit)?', true],
            'comando Symfony' => ['class Foo extends SymfonyCommand', '(?:Symfony)?Command', true],
            'não é comando' => ['class Foo extends CommandBus', '(?:Symfony)?Command', false],
        ];
    }

    public function test_o_core_nao_conhece_o_modulo_tenant_nos_ficheiros_de_runtime(): void
    {
        foreach (glob($this->raiz() . '/Modules/Core/app/Tenancy/{Jobs,Console,Contracts}/*.php', GLOB_BRACE) as $ficheiro) {
            $this->assertStringNotContainsString('Modules\\Tenant\\', file_get_contents($ficheiro), $ficheiro);
        }
    }

    public function test_as_excepcoes_declaradas_ainda_existem(): void
    {
        foreach ([...self::EXCEPCOES_DB_TABLE, ...self::EXCEPCOES_ELOQUENT] as $caminho) {
            $this->assertFileExists($this->raiz() . '/' . $caminho, "Excepção obsoleta em TenancyArquitecturaTest: {$caminho}");
        }
    }
}
