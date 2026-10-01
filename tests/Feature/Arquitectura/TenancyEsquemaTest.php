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

    public function test_toda_a_tabela_com_estabelecimento_id_e_tenant_id_tem_a_chave_composta(): void
    {
        $porConverter = config('tenancy.tabelas_por_converter');
        $erros = [];
        $comEstabelecimento = 0;

        foreach ($this->tabelas() as $tabela) {
            if (! Schema::hasColumn($tabela, 'estabelecimento_id')) {
                continue;
            }

            $comEstabelecimento++;

            if (! Schema::hasColumn($tabela, 'tenant_id')) {
                if (! in_array($tabela, $porConverter, true)) {
                    $erros[] = "{$tabela}: tem estabelecimento_id sem tenant_id e não consta em tabelas_por_converter.";
                }

                continue;
            }

            $temChaveComposta = collect(Schema::getForeignKeys($tabela))->contains(function (array $fk) {
                if ($fk['foreign_table'] !== 'estabelecimentos') {
                    return false;
                }

                $pares = array_combine($fk['columns'], $fk['foreign_columns']);
                ksort($pares);

                return $pares === ['estabelecimento_id' => 'id', 'tenant_id' => 'tenant_id'];
            });

            if (! $temChaveComposta) {
                $erros[] = "{$tabela}: falta a chave estrangeira composta (tenant_id, estabelecimento_id) → estabelecimentos (tenant_id, id).";
            }
        }

        $this->assertGreaterThan(5, $comEstabelecimento, 'Não foram encontradas as tabelas com estabelecimento_id.');
        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_toda_a_tabela_com_tenant_id_exige_not_null_e_chave_para_tenants(): void
    {
        $erros = [];
        $verificadas = 0;

        foreach ($this->tabelas() as $tabela) {
            if (! Schema::hasColumn($tabela, 'tenant_id')) {
                continue;
            }

            $verificadas++;
            $coluna = collect(Schema::getColumns($tabela))->firstWhere('name', 'tenant_id');

            if ($coluna['nullable']) {
                $erros[] = "{$tabela}: tenant_id devia ser NOT NULL.";
            }

            $temChave = collect(Schema::getForeignKeys($tabela))->contains(
                fn (array $fk) => $fk['foreign_table'] === 'tenants' && $fk['columns'] === ['tenant_id']
            );

            if (! $temChave) {
                $erros[] = "{$tabela}: falta a chave estrangeira tenant_id → tenants.";
            }
        }

        $this->assertGreaterThan(10, $verificadas, 'Não foram encontradas as tabelas com tenant_id.');
        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_as_tabelas_de_identidade_ja_nao_estao_na_lista_de_transicao(): void
    {
        $convertidas = [
            'users', 'dados_pessoas', 'documentos_pessoas', 'tipos_documentos', 'encarregados_alunos',
            'roles', 'role_permissoes', 'user_roles', 'user_permissoes',
            'personal_access_tokens',
        ];

        $aindaPorConverter = array_values(array_intersect($convertidas, config('tenancy.tabelas_por_converter')));

        $this->assertSame([], $aindaPorConverter, 'Tabelas de identidade ainda em tabelas_por_converter: '.implode(', ', $aindaPorConverter));

        foreach ($convertidas as $tabela) {
            $this->assertTrue(Schema::hasColumn($tabela, 'tenant_id'), "{$tabela} devia ter tenant_id.");
        }
    }

    public function test_as_tabelas_academicas_ja_nao_estao_na_lista_de_transicao(): void
    {
        $convertidas = [
            'ano_lectivos', 'periodos', 'eventos_calendario', 'horarios', 'cursos', 'disciplinas', 'salas',
            'turnos', 'niveis_academicos', 'turmas', 'turno_horarios', 'turma_salas', 'planos_curriculares',
            'plano_curricular_disciplinas', 'plano_curricular_anos_lectivos', 'plano_curricular_disciplina_periodos',
            'alunos', 'aluno_enquadramentos_academicos', 'matriculas', 'matricula_historicos', 'inscricoes_disciplinas',
        ];

        $aindaPorConverter = array_values(array_intersect($convertidas, config('tenancy.tabelas_por_converter')));

        $this->assertSame([], $aindaPorConverter, 'Tabelas académicas ainda em tabelas_por_converter: '.implode(', ', $aindaPorConverter));

        foreach ($convertidas as $tabela) {
            $this->assertTrue(Schema::hasColumn($tabela, 'tenant_id'), "{$tabela} devia ter tenant_id.");
        }

        $this->assertSame([], config('tenancy.tabelas_por_converter'));
    }

    public function test_as_sequencias_sao_por_tenant_e_ano(): void
    {
        foreach (['matricula_sequencias', 'matricula_registo_sequencias'] as $tabela) {
            $coluna = collect(Schema::getColumns($tabela))->firstWhere('name', 'tenant_id');

            $this->assertNotNull($coluna, "{$tabela} devia ter tenant_id.");
            $this->assertFalse($coluna['nullable'], "{$tabela}.tenant_id devia ser NOT NULL.");

            $this->assertTrue(
                collect(Schema::getForeignKeys($tabela))->contains(fn (array $fk) => $fk['foreign_table'] === 'tenants' && $fk['columns'] === ['tenant_id']),
                "{$tabela}.tenant_id devia ter chave estrangeira para tenants."
            );

            $unicos = collect(Schema::getIndexes($tabela))->where('unique', true)->map(fn (array $i) => $i['columns'])->all();

            $this->assertContains(['tenant_id', 'ano'], $unicos, "{$tabela} devia ter único (tenant_id, ano).");
            $this->assertNotContains(['ano'], $unicos, "{$tabela} não pode ter ano único global.");
        }
    }

    public function test_os_unicos_de_alunos_e_matriculas_sao_por_tenant(): void
    {
        $esperados = [
            'alunos' => ['tenant_id', 'numero_matricula'],
            'matriculas' => ['tenant_id', 'numero_registo_matricula'],
        ];

        foreach ($esperados as $tabela => $colunas) {
            $unicos = collect(Schema::getIndexes($tabela))->where('unique', true)->map(fn (array $i) => $i['columns'])->all();

            $this->assertContains($colunas, $unicos, "{$tabela} devia ter um índice único por ".implode(', ', $colunas).'.');
        }
    }

    public function test_os_unicos_de_identidade_sao_por_tenant(): void
    {
        $esperados = [
            'users' => [['tenant_id', 'email'], ['tenant_id', 'numero_matricula']],
            'dados_pessoas' => [['tenant_id', 'numero_identificacao']],
            'tipos_documentos' => [['tenant_id', 'slug']],
        ];

        foreach ($esperados as $tabela => $indices) {
            $unicos = collect(Schema::getIndexes($tabela))->where('unique', true)->map(fn (array $i) => $i['columns'])->all();

            foreach ($indices as $colunas) {
                $this->assertContains($colunas, $unicos, "{$tabela} devia ter um índice único por ".implode(', ', $colunas).'.');
            }

            foreach ($unicos as $colunas) {
                $this->assertNotSame(['email'], $colunas, "{$tabela} não pode ter email único global.");
                $this->assertNotSame(['numero_matricula'], $colunas, "{$tabela} não pode ter numero_matricula único global.");
                $this->assertNotSame(['numero_identificacao'], $colunas, "{$tabela} não pode ter numero_identificacao único global.");
                $this->assertNotSame(['slug'], $colunas, "{$tabela} não pode ter slug único global.");
            }
        }
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
