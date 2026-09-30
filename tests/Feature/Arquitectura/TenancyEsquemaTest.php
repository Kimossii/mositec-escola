<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\PertenceAoTenant;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

class TenancyEsquemaTest extends TestCase
{
    use RefreshDatabase;

    /** Tabelas de gestão que referem um tenant sem serem dados de tenant. */
    private const GLOBAIS_COM_TENANT_ID = ['domains'];

    /** @return string[] */
    private function tabelas(): array
    {
        return array_column(Schema::getTables(), 'name');
    }

    public function test_cada_tabela_esta_classificada_exactamente_uma_vez(): void
    {
        $globais = config('tenancy.tabelas_globais');
        $infra = config('tenancy.tabelas_infraestrutura');
        $porConverter = config('tenancy.tabelas_por_converter');
        $erros = [];

        $this->assertNotEmpty($this->tabelas());

        foreach ($this->tabelas() as $tabela) {
            $listas = (int) in_array($tabela, $globais, true)
                + (int) in_array($tabela, $infra, true)
                + (int) in_array($tabela, $porConverter, true);
            $temTenantId = Schema::hasColumn($tabela, 'tenant_id');

            if ($listas > 0 && $temTenantId && ! in_array($tabela, self::GLOBAIS_COM_TENANT_ID, true)) {
                $erros[] = "{$tabela}: tem tenant_id mas consta numa lista sem filtro de config/tenancy.php; ficaria exposta na validação.";
            } elseif ($listas > 1) {
                $erros[] = "{$tabela}: consta em mais de uma lista de config/tenancy.php.";
            } elseif ($listas === 0 && ! $temTenantId) {
                $erros[] = "{$tabela}: não tem tenant_id nem consta em nenhuma lista de config/tenancy.php.";
            } elseif (in_array($tabela, $porConverter, true) && $temTenantId) {
                $erros[] = "{$tabela}: já tem tenant_id; retire-a de tabelas_por_converter.";
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_as_listas_nao_tem_tabelas_que_ja_nao_existem(): void
    {
        $declaradas = [
            ...config('tenancy.tabelas_globais'),
            ...config('tenancy.tabelas_infraestrutura'),
            ...config('tenancy.tabelas_por_converter'),
        ];

        $inexistentes = array_values(array_diff($declaradas, $this->tabelas()));

        $this->assertSame([], $inexistentes, 'Tabelas declaradas em config/tenancy.php que não existem: ' . implode(', ', $inexistentes));
    }

    public function test_todo_o_model_de_uma_tabela_de_tenant_usa_a_trait(): void
    {
        $semFiltro = [
            ...config('tenancy.tabelas_globais'),
            ...config('tenancy.tabelas_infraestrutura'),
            ...config('tenancy.tabelas_por_converter'),
        ];
        $erros = [];
        $analisados = 0;

        foreach ($this->classesDeModels() as $classe) {
            $this->assertTrue(class_exists($classe), "Não foi possível carregar {$classe}.");

            if (! is_subclass_of($classe, Model::class) || (new ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            $analisados++;
            $tabela = (new $classe())->getTable();
            $usaTrait = in_array(PertenceAoTenant::class, class_uses_recursive($classe), true);
            $eDeTenant = ! in_array($tabela, $semFiltro, true);

            if ($eDeTenant && ! $usaTrait) {
                $erros[] = "{$classe} (tabela {$tabela}) é de tenant e não usa PertenceAoTenant.";
            }

            if (Schema::hasColumn($tabela, 'tenant_id') && (new $classe())->isFillable('tenant_id')) {
                $erros[] = "{$classe}: tenant_id não pode ser preenchível em massa.";
            }

            if (! $eDeTenant && $usaTrait) {
                $erros[] = "{$classe} (tabela {$tabela}) usa PertenceAoTenant, mas a tabela não é de tenant.";
            }
        }

        $this->assertGreaterThan(30, $analisados, 'Não foram encontrados os models dos módulos.');
        $this->assertSame([], $erros, implode("\n", $erros));
    }

    /**
     * Models da aplicação e de todos os módulos, incluindo subpastas de Models/.
     *
     * @return string[]
     */
    private function classesDeModels(): array
    {
        $classes = [];
        $raizes = ['App\\Models\\' => base_path('app/Models')];

        foreach (glob(base_path('Modules/*/app/Models')) as $pasta) {
            $raizes['Modules\\' . basename(dirname($pasta, 2)) . '\\Models\\'] = $pasta;
        }

        foreach ($raizes as $namespace => $pasta) {
            if (! is_dir($pasta)) {
                continue;
            }

            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php') {
                    $relativo = substr($ficheiro->getPathname(), strlen($pasta) + 1, -4);
                    $classes[] = $namespace . str_replace('/', '\\', $relativo);
                }
            }
        }

        return $classes;
    }
}
