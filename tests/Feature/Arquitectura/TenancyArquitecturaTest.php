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
        // O seeder raiz compõe os seeders de todos os módulos; é um dos pontos
        // fora do módulo Tenant que o pode referir.
        // Modules/Plataforma/ é a segunda excepção, explícita: a Plataforma é uma segunda interface
        // sobre as Actions e os Services de gestão de tenants (Plano 13) e depende do módulo Tenant
        // por desenho (D1). Qualquer outro módulo continua sem o poder importar.
        $violacoes = $this->ocorrencias('/Modules\\\\Tenant\\\\/', ['Modules/Tenant/', 'Modules/Plataforma/', 'database/seeders/DatabaseSeeder.php']);

        $this->assertSame([], $violacoes, "Estes ficheiros importam Modules\\Tenant. Os módulos da escola e o Core só podem conhecer TenantAtual e TenantContext:\n" . implode("\n", $violacoes));
    }

    /**
     * A Plataforma só conhece o Core (contratos e tipos) e o módulo Tenant (Plano 13, D1/D6);
     * nunca módulos de negócio (Usuario, Permissao, Autenticacao, módulos académicos...).
     */
    public function test_a_plataforma_so_importa_core_e_tenant(): void
    {
        $violacoes = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            if (! str_starts_with($caminho, 'Modules/Plataforma/')) {
                continue;
            }

            if (preg_match('/(?<![\\\\\w])Modules\\\\(?!Core\\\\|Tenant\\\\|Plataforma\\\\)\w+/', $conteudo, $achado) === 1) {
                $violacoes[] = "{$caminho}: {$achado[0]}";
            }
        }

        $this->assertSame([], $violacoes, "A Plataforma só pode importar Core e Tenant:\n" . implode("\n", $violacoes));
    }

    /** O Core, o Tenant e os módulos da escola não conhecem a Plataforma (só o seeder raiz a compõe). */
    public function test_ninguem_importa_a_plataforma(): void
    {
        $violacoes = $this->ocorrencias('/Modules\\\\Plataforma\\\\/', ['Modules/Plataforma/', 'database/seeders/DatabaseSeeder.php']);

        $this->assertSame([], $violacoes, "Estes ficheiros importam Modules\\Plataforma:\n" . implode("\n", $violacoes));
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

    /**
     * Comandos da Plataforma: gerem super admins (tabela global) e não operam sobre dados de escola,
     * por isso não têm --tenant/--todos. Excepção explícita, por ficheiro (nunca por pasta, para um
     * comando futuro de dados de escola não escapar à convenção). O teste seguinte garante que estes
     * comandos continuam sem tocar no contexto de tenant.
     */
    private const COMANDOS_DA_PLATAFORMA = [
        'Modules/Plataforma/app/Console/CriarSuperAdminCommand.php',
        'Modules/Plataforma/app/Console/RedefinirSuperAdminCommand.php',
        'Modules/Financeiro/app/Console/CambioPlataformaCommand.php',
    ];

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
            if (! str_starts_with($caminho, 'Modules/') || str_starts_with($caminho, 'Modules/Tenant/') || in_array($caminho, self::EXCEPCOES_FILAS_E_COMANDOS, true) || in_array($caminho, self::COMANDOS_DA_PLATAFORMA, true)) {
                continue;
            }

            if ($this->declaraClasse($conteudo, '(?:Symfony)?Command') && ! preg_match('/^[ \t]+use\s+(?:ParaTodosOsTenants|EscolheUmTenant)\b/m', $conteudo)) {
                $violacoes[] = $caminho;
            }
        }

        $this->assertSame([], $violacoes, "Comandos fora do módulo Tenant sem ParaTodosOsTenants/EscolheUmTenant (--tenant/--todos):\n" . implode("\n", $violacoes));
    }

    public function test_os_comandos_da_plataforma_nao_tocam_no_contexto_de_tenant(): void
    {
        $codigo = $this->codigoDeAplicacao();

        foreach (self::COMANDOS_DA_PLATAFORMA as $caminho) {
            $this->assertArrayHasKey($caminho, $codigo);
            $this->assertStringNotContainsString('TenantContext', $codigo[$caminho], $caminho);
            $this->assertStringNotContainsString('Modules\\Tenant\\Models', $codigo[$caminho], $caminho);
        }
    }

    /** Operações que abrem, fecham, trocam ou memorizam contexto de tenant. A leitura (temTenant, id, atual) é permitida. */
    private const PADRAO_OPERACAO_DE_CONTEXTO = '/\b(?:definir|limpar|executarComo|lembrar)\b/';

    /** Ficheiro que refere o contexto ou o obtém do container (sem o nomear). */
    private const PADRAO_TEM_ACESSO_AO_CONTEXTO = '/TenantContext|\bresolve\s*\(|\bapp\s*\(|->\s*make\s*\(|->\s*get\s*\(\s*[\'"]/';

    /** Formas comuns de chamar um método sem o escrever (incluindo o nome em variável): não existem no código da Plataforma que acede ao contexto. */
    private const PADRAO_CHAMADA_DINAMICA = '/->\s*\{|::\s*\{|->\s*\$\w+\s*\(|::\s*\$\w+\s*\(|\bcall_user_func(?:_array)?\s*\(|Closure::fromCallable|->\s*call\s*\(|\bnew\s+ReflectionMethod/';

    private function semComentarios(string $codigo): string
    {
        $saida = '';

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $saida .= $token[1];
            } else {
                $saida .= $token;
            }
        }

        return $saida;
    }

    /**
     * Regra de ouro (Plano 13), para um ficheiro da Plataforma.
     *
     * @return string[] motivos de violação
     */
    private function violacoesDaRegraDeOuro(string $codigo, bool $camadaHttpOuRotas): array
    {
        $codigo = $this->semComentarios($codigo);
        $motivos = [];

        if ($camadaHttpOuRotas && str_contains($codigo, 'TenantContext')) {
            $motivos[] = 'refere TenantContext na camada HTTP/rotas (nem injecção, nem leitura)';
        }

        if (preg_match(self::PADRAO_TEM_ACESSO_AO_CONTEXTO, $codigo) === 1) {
            if (preg_match(self::PADRAO_OPERACAO_DE_CONTEXTO, $codigo, $achado) === 1) {
                $motivos[] = "usa '{$achado[0]}' num ficheiro com acesso ao contexto";
            }

            if (preg_match(self::PADRAO_CHAMADA_DINAMICA, $codigo, $achado) === 1) {
                $motivos[] = "chamada dinâmica '{$achado[0]}' num ficheiro com acesso ao contexto";
            }
        }

        return $motivos;
    }

    /** @return array<string, string> caminho relativo => conteúdo, de TODO o módulo Plataforma (sem testes) */
    private function codigoDaPlataforma(): array
    {
        $ficheiros = [];
        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->raiz() . '/Modules/Plataforma', FilesystemIterator::SKIP_DOTS));

        foreach ($iterador as $ficheiro) {
            $relativo = str_replace($this->raiz() . '/', '', $ficheiro->getPathname());

            if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php' && ! str_starts_with($relativo, 'Modules/Plataforma/tests/')) {
                $ficheiros[$relativo] = file_get_contents($ficheiro->getPathname());
            }
        }

        return $ficheiros;
    }

    /**
     * Nada na Plataforma (app, routes, providers, seeders, config) abre, fecha, troca ou memoriza o
     * contexto de tenant, de forma directa ou dinâmica; a camada HTTP e as rotas nem o referem.
     * Só uma Action fora da Plataforma pode usar TenantContext::executarComo.
     */
    public function test_a_plataforma_nao_abre_contexto_de_tenant(): void
    {
        $codigo = $this->codigoDaPlataforma();
        $http = 0;
        $violacoes = [];

        foreach ($codigo as $caminho => $conteudo) {
            $camadaHttp = str_starts_with($caminho, 'Modules/Plataforma/app/Http/') || str_starts_with($caminho, 'Modules/Plataforma/routes/');
            $http += $camadaHttp ? 1 : 0;

            foreach ($this->violacoesDaRegraDeOuro($conteudo, $camadaHttp) as $motivo) {
                $violacoes[] = "{$caminho}: {$motivo}";
            }
        }

        $this->assertGreaterThan(0, $http, 'Nenhum ficheiro HTTP/rotas da Plataforma foi analisado.');
        $this->assertArrayHasKey('Modules/Plataforma/app/Providers/PlataformaServiceProvider.php', $codigo);
        $this->assertArrayHasKey('Modules/Plataforma/config/config.php', $codigo);
        $this->assertArrayHasKey('Modules/Plataforma/database/seeders/PlataformaDesenvolvimentoSeeder.php', $codigo);
        $this->assertSame([], $violacoes, "A Plataforma não pode abrir contexto de tenant:\n" . implode("\n", $violacoes));
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function exemplosDeRegraDeOuro(): array
    {
        return [
            'chamada directa' => ['<?php app(TenantContext::class)->executarComo($t, fn () => 1);', false, true],
            'definir estático' => ['<?php TenantContext::definir($t);', false, true],
            'limpar injectado' => ['<?php class A { function __construct(private TenantContext $c) {} function f() { $this->c->limpar(); } }', false, true],
            'lembrar' => ['<?php $ctx = resolve(TenantContext::class); $ctx->lembrar("k", fn () => 1);', false, true],
            'array-callable' => ['<?php $ctx = app(TenantContext::class); call_user_func([$ctx, "definir"], $t);', false, true],
            'array-callable sem call_user_func' => ['<?php $ctx = app(TenantContext::class); [$ctx, "definir"]($t);', false, true],
            'string do método' => ['<?php $ctx = app(TenantContext::class); $m = "executarComo"; $ctx->$m($t, fn () => 1);', false, true],
            'nome do método em variável, partido' => ["<?php \$m = 'executar' . 'Como'; app(TenantContext::class)->\$m(\$t, fn () => 1);", false, true],
            'nome do método em variável, estático' => ["<?php \$m = 'de' . 'finir'; \$c = TenantContext::class; \$c::\$m(\$t);", false, true],
            'variável como método sem acesso ao contexto' => ['<?php class A { function f() { $x = "total"; return $this->$x(); } }', false, false],
            'variável como método, só a leitura do contexto fora da camada http' => ['<?php class A { function f(TenantContext $c) { $m = "id"; return $c->$m(); } }', false, true],
            'método dinâmico' => ['<?php $ctx = app(TenantContext::class); $ctx->{$m}($t);', false, true],
            'call_user_func sem nomear a operação' => ['<?php $ctx = app(TenantContext::class); call_user_func([$ctx, $m]);', false, true],
            'container call' => ['<?php app()->call([app(TenantContext::class), "limpar"]);', false, true],
            'obtido sem nomear a classe' => ['<?php app("tenancy.contexto")->limpar();', false, true],
            'injecção na camada http' => ['<?php class C { function __construct(private TenantContext $c) {} }', true, true],
            'leitura permitida' => ['<?php class A { function f(TenantContext $c) { return $c->temTenant() || $c->id() || $c->atual(); } }', false, false],
            'comentário não conta' => ["<?php // TenantContext::limpar()\n/** executarComo */ \$x = app('session');", false, false],
            'sem acesso ao contexto' => ['<?php $lista = []; $lista[] = "limpar"; $this->limpar();', false, false],
            'leitura na camada http é recusada' => ['<?php class C { function f(TenantContext $c) { return $c->temTenant(); } }', true, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exemplosDeRegraDeOuro')]
    public function test_o_varrimento_da_regra_de_ouro_apanha_o_que_deve(string $codigo, bool $camadaHttp, bool $esperado): void
    {
        $this->assertSame($esperado, $this->violacoesDaRegraDeOuro($codigo, $camadaHttp) !== [], $codigo);
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

    /** Arquitectura final da Plataforma (Plano 13, Task 7): fronteiras de importação, contexto, rotas, tabelas e bypass. */
    public function test_arquitectura_final_da_plataforma(): void
    {
        $codigo = $this->codigoDeAplicacao();
        $controllers = 0;
        $models = 0;

        foreach ($codigo as $caminho => $conteudo) {
            if (! str_starts_with($caminho, 'Modules/Plataforma/')) {
                continue;
            }

            // Só importa Core e Tenant (e o próprio módulo).
            $this->assertSame(0, preg_match('/(?<![\\\\\w])Modules\\\\(?!Core\\\\|Tenant\\\\|Plataforma\\\\)\w+/', $conteudo), "{$caminho}: importa um módulo que não é Core nem Tenant.");

            if (str_starts_with($caminho, 'Modules/Plataforma/app/Http/Controllers/')) {
                $controllers++;
                $this->assertDoesNotMatchRegularExpression('/\bexecutarComo\b/', $this->semComentarios($conteudo), "{$caminho}: um controller não abre contexto.");
            }

            if (str_starts_with($caminho, 'Modules/Plataforma/app/Models/')) {
                $models++;
                $this->assertStringNotContainsString('PertenceAoTenant', $this->semComentarios($conteudo), "{$caminho}: um model da Plataforma não é de tenant.");
            }

            // O scanner de bypass do Eloquent cobre o módulo, sem excepções.
            foreach ($this->violacoesDeBypass($conteudo) as $excerto) {
                $this->fail("{$caminho}: {$excerto}");
            }
        }

        $this->assertGreaterThan(5, $controllers);
        $this->assertGreaterThanOrEqual(2, $models);
        foreach ([...self::EXCEPCOES_ELOQUENT, ...self::EXCEPCOES_DB_TABLE] as $excepcao) {
            $this->assertStringNotContainsString('Modules/Plataforma/', $excepcao, 'A Plataforma não tem excepções ao scanner de bypass.');
        }
        $this->assertArrayHasKey('Modules/Plataforma/routes/web.php', $codigo, 'O scanner tem de ver as rotas da Plataforma.');

        // As rotas do painel não estão nos grupos da escola.
        $rotas = $this->semComentarios($codigo['Modules/Plataforma/routes/web.php']);
        $this->assertDoesNotMatchRegularExpression("/middleware\\(\\s*\\[?\\s*['\"](?:web|api)['\"]/", $rotas);
        $this->assertStringNotContainsString("->prefix(", $rotas);

        // As duas tabelas da Plataforma são globais.
        $config = require $this->raiz() . '/config/tenancy.php';
        foreach (['super_admins', 'plataforma_auditoria'] as $tabela) {
            $this->assertContains($tabela, $config['tabelas_globais'], $tabela);
        }
    }

    public function test_as_excepcoes_declaradas_ainda_existem(): void
    {
        foreach ([...self::EXCEPCOES_DB_TABLE, ...self::EXCEPCOES_ELOQUENT, ...self::COMANDOS_DA_PLATAFORMA] as $caminho) {
            $this->assertFileExists($this->raiz() . '/' . $caminho, "Excepção obsoleta em TenancyArquitecturaTest: {$caminho}");
        }
    }
}
