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
     * Temporário. O plano das sequências substitui estes dois geradores por um
     * gerador único no Core, que passa a ser a única entrada desta lista.
     */
    private const EXCEPCOES_DB_TABLE = [
        'Modules/Usuario/app/Services/GeradorMatriculaService.php',
        'Modules/Matricula/app/Services/GeradorNumeroRegistoMatriculaService.php',
    ];

    /**
     * Temporário. O plano de identidade e permissões passa o PermissaoCache
     * para CacheTenant e esvazia esta lista.
     */
    private const EXCEPCOES_CACHE = [
        'Modules/Permissao/app/Support/PermissaoCache.php',
    ];

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

    public function test_db_table_so_e_usado_nas_excepcoes_declaradas(): void
    {
        $violacoes = $this->ocorrencias('/\bDB::(table|select|selectOne|insert|update|delete|statement|unprepared|query|connection)\s*\(/', self::EXCEPCOES_DB_TABLE);

        $this->assertSame([], $violacoes, "As consultas directas pela fachada DB ignoram o isolamento por tenant. Use o model (com PertenceAoTenant):\n" . implode("\n", $violacoes));
    }

    public function test_a_cache_so_e_usada_nas_excepcoes_declaradas(): void
    {
        $violacoes = $this->ocorrencias('/\bCache::|\bcache\s*\(/', ['Modules/Core/app/Tenancy/', ...self::EXCEPCOES_CACHE]);

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

    public function test_as_excepcoes_declaradas_ainda_existem(): void
    {
        foreach ([...self::EXCEPCOES_DB_TABLE, ...self::EXCEPCOES_CACHE] as $caminho) {
            $this->assertFileExists($this->raiz() . '/' . $caminho, "Excepção obsoleta em TenancyArquitecturaTest: {$caminho}");
        }
    }
}
