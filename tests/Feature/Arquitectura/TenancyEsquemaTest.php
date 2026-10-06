<?php

namespace Tests\Feature\Arquitectura;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\PertenceAoTenant;
use ReflectionClass;
use Tests\Concerns\DescobreModels;
use Tests\TestCase;

class TenancyEsquemaTest extends TestCase
{
    use DescobreModels;
    use RefreshDatabase;

    /** Tabelas de gestão que referem um tenant sem serem dados de tenant. */
    private const GLOBAIS_COM_TENANT_ID = ['domains'];

    /** @return string[] */
    private function tabelas(): array
    {
        return array_column(Schema::getTables(), 'name');
    }

    /**
     * Classes de uma tabela: tenant (tem tenant_id), global ou infra-estrutura. Exactamente uma.
     * `domains` tem tenant_id por ser gestão de tenants: conta como global, não como de tenant.
     *
     * @param  string[]  $tabelas
     * @return string[] um erro por tabela mal classificada
     */
    private function errosDeClassificacao(array $tabelas): array
    {
        $globais = config('tenancy.tabelas_globais');
        $infra = config('tenancy.tabelas_infraestrutura');
        $erros = [];

        foreach ($tabelas as $tabela) {
            $classes = [];

            if (Schema::hasColumn($tabela, 'tenant_id') && ! in_array($tabela, self::GLOBAIS_COM_TENANT_ID, true)) {
                $classes[] = 'tenant (tem tenant_id)';
            }

            if (in_array($tabela, $globais, true)) {
                $classes[] = 'global (tabelas_globais)';
            }

            if (in_array($tabela, $infra, true)) {
                $classes[] = 'infra-estrutura (tabelas_infraestrutura)';
            }

            if (count($classes) === 0) {
                $erros[] = "{$tabela}: não tem tenant_id nem consta em tabelas_globais ou tabelas_infraestrutura.";
            } elseif (count($classes) > 1) {
                $erros[] = "{$tabela}: está em mais de uma classe: " . implode(' e ', $classes) . '.';
            }
        }

        return $erros;
    }

    public function test_cada_tabela_esta_classificada_exactamente_uma_vez(): void
    {
        $this->assertNotEmpty($this->tabelas());

        $erros = $this->errosDeClassificacao($this->tabelas());

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_uma_tabela_nova_sem_classificacao_faz_o_teste_falhar(): void
    {
        Schema::create('tabela_nova_sem_classificacao', function ($tabela) {
            $tabela->id();
        });

        $erros = $this->errosDeClassificacao($this->tabelas());

        $this->assertCount(1, $erros);
        $this->assertStringContainsString('tabela_nova_sem_classificacao: não tem tenant_id', $erros[0]);
    }

    public function test_uma_tabela_em_mais_de_uma_classe_faz_o_teste_falhar(): void
    {
        // Com tenant_id e declarada global: ficaria exposta na validação.
        config(['tenancy.tabelas_globais' => [...config('tenancy.tabelas_globais'), 'cursos']]);
        // Global e infra-estrutura ao mesmo tempo.
        config(['tenancy.tabelas_infraestrutura' => [...config('tenancy.tabelas_infraestrutura'), 'modulos']]);

        $erros = $this->errosDeClassificacao($this->tabelas());

        $this->assertCount(2, $erros);
        $this->assertStringContainsString('cursos: está em mais de uma classe', implode("\n", $erros));
        $this->assertStringContainsString('modulos: está em mais de uma classe', implode("\n", $erros));
    }

    public function test_as_listas_nao_tem_tabelas_que_ja_nao_existem(): void
    {
        $declaradas = [
            ...config('tenancy.tabelas_globais'),
            ...config('tenancy.tabelas_infraestrutura'),
        ];

        $inexistentes = array_values(array_diff($declaradas, $this->tabelas()));

        $this->assertSame([], $inexistentes, 'Tabelas declaradas em config/tenancy.php que não existem: ' . implode(', ', $inexistentes));
    }

    public function test_a_lista_de_transicao_foi_removida(): void
    {
        $this->assertNull(config('tenancy.tabelas_por_converter'), 'A lista de transição (spec §9.5.1) devia ter sido removida de config/tenancy.php.');
    }

    public function test_todo_o_model_de_uma_tabela_de_tenant_usa_a_trait(): void
    {
        $semFiltro = [
            ...config('tenancy.tabelas_globais'),
            ...config('tenancy.tabelas_infraestrutura'),
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
        $erros = [];
        $comEstabelecimento = 0;

        foreach ($this->tabelas() as $tabela) {
            if (! Schema::hasColumn($tabela, 'estabelecimento_id')) {
                continue;
            }

            $comEstabelecimento++;

            if (! Schema::hasColumn($tabela, 'tenant_id')) {
                $erros[] = "{$tabela}: tem estabelecimento_id sem tenant_id.";

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

    public function test_as_tabelas_de_identidade_tem_tenant_id(): void
    {
        $convertidas = [
            'users', 'dados_pessoas', 'documentos_pessoas', 'tipos_documentos', 'encarregados_alunos',
            'roles', 'role_permissoes', 'user_roles', 'user_permissoes',
            'personal_access_tokens',
        ];

        foreach ($convertidas as $tabela) {
            $this->assertTrue(Schema::hasColumn($tabela, 'tenant_id'), "{$tabela} devia ter tenant_id.");
        }
    }

    public function test_as_tabelas_academicas_tem_tenant_id(): void
    {
        $convertidas = [
            'ano_lectivos', 'periodos', 'eventos_calendario', 'horarios', 'cursos', 'disciplinas', 'salas',
            'turnos', 'niveis_academicos', 'turmas', 'turno_horarios', 'turma_salas', 'planos_curriculares',
            'plano_curricular_disciplinas', 'plano_curricular_anos_lectivos', 'plano_curricular_disciplina_periodos',
            'alunos', 'aluno_enquadramentos_academicos', 'matriculas', 'matricula_historicos', 'inscricoes_disciplinas',
        ];

        foreach ($convertidas as $tabela) {
            $this->assertTrue(Schema::hasColumn($tabela, 'tenant_id'), "{$tabela} devia ter tenant_id.");
        }
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
}
