<?php

namespace Tests\Feature\Provisioning;

use PHPUnit\Framework\TestCase;

/** Dependências do provisioning verificadas por leitura dos ficheiros (spec §16). */
class ProvisioningArquitecturaTest extends TestCase
{
    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> */
    private function ficheiros(string $padrao): array
    {
        $resultado = [];

        foreach (glob($this->raiz() . '/' . $padrao) as $caminho) {
            $resultado[str_replace($this->raiz() . '/', '', $caminho)] = file_get_contents($caminho);
        }

        return $resultado;
    }

    private function todosOsPhp(string $pasta): array
    {
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->raiz() . '/' . $pasta, \FilesystemIterator::SKIP_DOTS));
        $resultado = [];

        foreach ($iterador as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $resultado[$f->getPathname()] = file_get_contents($f->getPathname());
            }
        }

        return $resultado;
    }

    public function test_ha_um_provisionador_por_modulo_e_todos_implementam_o_contrato(): void
    {
        $provisionadores = $this->ficheiros('Modules/*/app/Provisioning/*.php');

        $this->assertCount(6, $provisionadores);

        foreach ($provisionadores as $caminho => $conteudo) {
            $this->assertStringContainsString('implements ProvisionaTenant', $conteudo, $caminho);
        }
    }

    public function test_nenhum_provisionador_importa_o_modulo_tenant(): void
    {
        foreach ($this->ficheiros('Modules/*/app/Provisioning/*.php') as $caminho => $conteudo) {
            $this->assertDoesNotMatchRegularExpression('/Modules\\\\Tenant\\\\/', $conteudo, $caminho);
        }
    }

    public function test_o_modulo_tenant_nao_importa_modulos_de_negocio(): void
    {
        foreach ($this->todosOsPhp('Modules/Tenant/app') + $this->todosOsPhp('Modules/Tenant/database') as $caminho => $conteudo) {
            $this->assertDoesNotMatchRegularExpression(
                '/^use\s+Modules\\\\(?!Core\\\\|Tenant\\\\)/m',
                $conteudo,
                "{$caminho} importa um módulo de negócio.",
            );
        }
    }

    public function test_o_core_nao_importa_modulos_de_negocio(): void
    {
        foreach ($this->todosOsPhp('Modules/Core/app/Tenancy') as $caminho => $conteudo) {
            $this->assertDoesNotMatchRegularExpression('/^use\s+Modules\\\\(?!Core\\\\)/m', $conteudo, $caminho);
        }
    }

    public function test_a_action_descobre_os_provisionadores_so_pela_etiqueta(): void
    {
        // O Tenant não conhece os provisionadores pelo nome: descobre-os só pela etiqueta.
        $conteudo = file_get_contents($this->raiz() . '/Modules/Tenant/app/Actions/CriarTenantAction.php');

        $this->assertStringContainsString('ProvisionaTenant::ETIQUETA', $conteudo);
        $this->assertDoesNotMatchRegularExpression('/Provisionar[A-Z]\w+/', $conteudo);
    }
}
