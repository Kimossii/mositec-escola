<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\AnoLectivo\Services\AnoLectivoConsultaService;
use Modules\Core\Models\Horario;
use Modules\Core\Services\HorarioConsultaService;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Services\CursoConsultaService;
use Modules\Disciplina\Services\DisciplinaConsultaService;
use Modules\Infraestrutura\Services\SalaConsultaService;
use Modules\Matricula\Services\MatriculaConsultaService;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Services\PermissaoConsultaService;
use Modules\Permissao\Support\PermissaoCache;
use Modules\PlanoCurricular\Services\PlanoCurricularConsultaService;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Services\NivelAcademicoConsultaService;
use Modules\Turma\Services\TurmaConsultaService;
use Modules\Turma\Services\TurnoConsultaService;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\UsuarioConsultaService;
use ReflectionClass;
use Tests\Concerns\DescobreModels;
use Tests\Concerns\PopulaDadosAcademicos;
use Tests\TestCase;

/**
 * Bateria de isolamento (spec §19.2) com dois tenants populados.
 *
 * Duas partes:
 *  1. MAPA: cada linha da matriz → testes já existentes que a provam. Falha se a linha, o ficheiro
 *     ou o método referido deixar de existir (renomear ou apagar um teste de isolamento é visível).
 *  2. LACUNAS: cenários próprios para o que nenhum teste de módulo provava (todos os models sem
 *     contexto, scope por tenant em todos os models, os *ConsultaService, o prefixo da cache,
 *     ficheiros e estado do tenant). Cada cenário tem controlo positivo e negativo.
 */
class MatrizIsolamentoTest extends TestCase
{
    use DescobreModels;
    use PopulaDadosAcademicos;
    use RefreshDatabase;

    /** Linhas de §19.2 (mais "Estado do tenant", §19.4) → [ficheiro => métodos]. */
    private const MAPA = [
        'Listagem' => [
            'tests/Feature/Tenancy/AcademicoIsolamentoTest.php' => ['test_cada_listagem_academica_mostra_so_os_dados_do_dominio'],
            'tests/Feature/Tenancy/IdentidadeIsolamentoTest.php' => ['test_a_listagem_de_utilizadores_mostra_so_os_do_tenant_do_dominio'],
            'Modules/Permissao/tests/Feature/PermissaoTenancyTest.php' => ['test_cada_tenant_tem_os_seus_perfis_e_permissoes'],
            'Modules/Core/tests/Feature/HorarioTenancyTest.php' => ['test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio'],
        ],
        'ID directo / route model binding' => [
            'tests/Feature/Tenancy/AcademicoIsolamentoTest.php' => ['test_o_detalhe_de_b_no_dominio_de_a_da_404_e_o_de_a_da_200'],
            'tests/Feature/Tenancy/IdentidadeIsolamentoTest.php' => ['test_pedir_no_dominio_de_a_um_utilizador_de_b_da_404'],
            'Modules/Permissao/tests/Feature/PermissaoTenancyTest.php' => ['test_perfil_de_outro_tenant_nao_e_encontrado_por_id'],
            'Modules/AnoLectivo/tests/Feature/AnoLectivoTenancyTest.php' => ['test_pedir_no_dominio_de_a_um_periodo_ou_evento_de_b_da_404'],
        ],
        'exists' => [
            'Modules/Core/tests/Feature/Tenancy/VerificadorPresencaTenantTest.php' => ['test_exists_rejeita_um_id_de_outro_tenant', 'test_exists_aceita_um_id_do_tenant_corrente'],
            'Modules/Usuario/tests/Feature/UsuarioTenancyTest.php' => ['test_a_regra_exists_nao_ve_utilizadores_de_outro_tenant'],
            'Modules/Curso/tests/Feature/CursoTenancyTest.php' => ['test_exists_rejeita_o_id_de_outro_tenant'],
            'Modules/Matricula/tests/Feature/MatriculaTenancyTest.php' => ['test_exists_rejeita_o_id_de_outro_tenant'],
        ],
        'unique' => [
            'Modules/Core/tests/Feature/Tenancy/VerificadorPresencaTenantTest.php' => ['test_unique_permite_o_mesmo_valor_noutro_tenant', 'test_unique_rejeita_duplicado_no_mesmo_tenant'],
            'Modules/Usuario/tests/Feature/UsuarioTenancyTest.php' => ['test_o_mesmo_email_pode_existir_em_dois_tenants', 'test_a_regra_unique_so_olha_para_o_tenant_corrente'],
            'Modules/Curso/tests/Feature/CursoTenancyTest.php' => ['test_unique_e_por_tenant'],
            'Modules/Aluno/tests/Feature/AlunoTenancyTest.php' => ['test_unique_e_por_tenant'],
            'Modules/Matricula/tests/Feature/MatriculaTenancyTest.php' => ['test_unique_e_por_tenant'],
        ],
        'Repository / Service' => [
            'tests/Feature/Tenancy/MatrizIsolamentoTest.php' => ['test_os_servicos_de_consulta_no_contexto_de_a_nao_devolvem_dados_de_b'],
            'Modules/Usuario/tests/Feature/UsuarioTenancyTest.php' => ['test_listagem_e_find_nao_devolvem_utilizadores_de_outro_tenant'],
        ],
        'Action' => [
            'Modules/Curso/tests/Feature/CursoTenancyTest.php' => ['test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao'],
            'Modules/Aluno/tests/Feature/AlunoTenancyTest.php' => ['test_a_action_de_criar_mantem_pessoa_e_aluno_no_tenant_do_contexto'],
            'Modules/Matricula/tests/Feature/MatriculaTenancyTest.php' => ['test_historico_e_inscricoes_gravam_o_tenant_do_contexto'],
            'Modules/Permissao/tests/Feature/PermissaoTenancyTest.php' => ['test_sincronizar_permissoes_grava_tenant_id_nas_escritas_em_massa', 'test_alterar_o_tenant_de_um_perfil_e_proibido'],
            'tests/Feature/Tenancy/MatrizIsolamentoTest.php' => ['test_alterar_o_tenant_de_qualquer_model_lanca_excepcao'],
        ],
        'Sem contexto' => [
            'tests/Feature/Tenancy/MatrizIsolamentoTest.php' => ['test_sem_contexto_a_leitura_e_a_escrita_de_qualquer_model_lancam'],
            'Modules/Core/tests/Unit/Tenancy/TenantContextTest.php' => ['test_id_sem_tenant_lanca_excepcao'],
            'Modules/Estabelecimento/tests/Feature/EstabelecimentoTenancyTest.php' => ['test_current_sem_contexto_lanca_tenant_nao_resolvido'],
        ],
        'Sequências' => [
            'tests/Feature/Tenancy/SequenciasIsolamentoTest.php' => ['test_duas_escolas_no_mesmo_ano_obtem_ambas_0001_sem_colisao'],
            'Modules/Core/tests/Feature/GeradorSequenciaTest.php' => ['test_a_e_b_comecam_cada_um_em_0001_no_mesmo_ano', 'test_sem_contexto_lanca_tenant_nao_resolvido_e_nao_cria_linhas'],
        ],
        'Cache' => [
            'Modules/Core/tests/Unit/Tenancy/CacheTenantTest.php' => ['test_prefixa_a_chave_com_o_tenant', 'test_esquecer_num_tenant_nao_afecta_o_outro'],
            'Modules/Permissao/tests/Feature/PermissaoCacheTest.php' => ['test_invalidar_tudo_num_tenant_nao_afecta_a_versao_do_outro'],
            'tests/Feature/Tenancy/MatrizIsolamentoTest.php' => [
                'test_a_cache_de_permissoes_usa_o_prefixo_do_tenant_na_loja_real',
                'test_esquecer_ou_invalidar_permissoes_em_a_nao_afecta_b',
            ],
            'tests/Feature/Arquitectura/TenancyArquitecturaTest.php' => ['test_a_cache_so_e_usada_nas_excepcoes_declaradas'],
        ],
        'Ficheiros' => [
            'Modules/Aluno/tests/Feature/AlunoFotoTest.php' => ['test_a_foto_e_gravada_no_disco_privado_com_prefixo_do_tenant', 'test_aluno_de_b_no_dominio_de_a_da_404'],
            'Modules/Usuario/tests/Feature/DocumentoPessoaFicheirosTest.php' => ['test_o_documento_fica_sob_o_prefixo_do_tenant_e_da_pessoa', 'test_download_e_visualizar_de_documento_de_b_a_partir_de_a_da_404'],
            'Modules/Estabelecimento/tests/Feature/EstabelecimentoFicheirosTest.php' => ['test_o_logotipo_fica_no_disco_public_sob_o_prefixo_do_tenant'],
            'tests/Feature/Arquitectura/TenancyArquitecturaTest.php' => ['test_as_gravacoes_de_ficheiros_usam_caminho_tenant'],
        ],
        'Jobs' => [
            'tests/Feature/Jobs/ComTenantTest.php' => ['test_cada_job_reentra_no_tenant_que_o_despachou_no_mesmo_worker', 'test_despacho_sem_contexto_falha'],
            'tests/Feature/Jobs/UnicoPorTenantTest.php' => ['test_dois_tenants_enfileiram_o_mesmo_job_unico_e_o_repetido_do_mesmo_tenant_e_recusado'],
            'tests/Feature/Arquitectura/TenancyArquitecturaTest.php' => ['test_jobs_enfileirados_usam_com_tenant', 'test_jobs_unicos_usam_unico_por_tenant'],
        ],
        'Commands' => [
            'tests/Feature/Comandos/ConvencaoDeComandosTest.php' => ['test_tenant_valido_corre_so_esse', 'test_sem_opcao_falha_e_nao_corre_em_nenhum_tenant'],
            'Modules/Autenticacao/tests/Feature/PodarTokensCommandTest.php' => ['test_o_comando_apaga_os_expirados_do_tenant_e_deixa_o_outro_intacto'],
            'tests/Feature/Arquitectura/TenancyArquitecturaTest.php' => ['test_comandos_de_dados_de_escola_usam_a_convencao_de_tenant'],
        ],
        'Autenticação' => [
            'Modules/Autenticacao/tests/Feature/SessaoTenancyTest.php' => ['test_credenciais_do_tenant_a_nao_entram_no_dominio_de_b', 'test_sessao_de_a_reenviada_ao_dominio_de_b_nao_autentica'],
            'Modules/Autenticacao/tests/Feature/TokenTenancyTest.php' => ['test_token_do_tenant_a_e_rejeitado_no_dominio_de_b'],
            'Modules/Autenticacao/tests/Feature/LimiteTentativasLoginTest.php' => ['test_falhas_numa_conta_em_a_nao_bloqueiam_a_mesma_conta_em_b', 'test_api_falhas_numa_conta_em_a_nao_bloqueiam_a_mesma_conta_em_b'],
        ],
        'Consistência' => [
            'tests/Feature/Tenancy/AcademicoIsolamentoTest.php' => ['test_as_oito_tabelas_com_estabelecimento_exigem_a_chave_composta'],
            'Modules/Estabelecimento/tests/Feature/EstabelecimentoTenancyTest.php' => ['test_segundo_estabelecimento_no_mesmo_tenant_e_rejeitado_pela_bd', 'test_etapa_nao_pode_apontar_para_o_estabelecimento_de_outro_tenant'],
            'Modules/Curso/tests/Feature/CursoTenancyTest.php' => ['test_a_bd_rejeita_estabelecimento_de_outro_tenant'],
            'tests/Feature/Arquitectura/TenancyEsquemaTest.php' => ['test_toda_a_tabela_com_estabelecimento_id_e_tenant_id_tem_a_chave_composta', 'test_toda_a_tabela_com_tenant_id_exige_not_null_e_chave_para_tenants'],
        ],
        'Estado do tenant' => [
            'tests/Feature/CicloDeVida/EstadoDoTenantHttpTest.php' => [
                'test_suspenso_bloqueia_todos_os_caminhos_web', 'test_suspenso_api_responde_403_json_sem_expor_dados', 'test_suspenso_recusa_o_login',
                'test_token_sanctum_e_rejeitado_em_suspenso_e_volta_a_valer_ao_reactivar', 'test_encerrado_responde_404_em_todos_os_caminhos_web', 'test_encerrado_recusa_login_e_token',
            ],
            'tests/Feature/Tenancy/MatrizIsolamentoTest.php' => ['test_ficheiros_de_um_tenant_suspenso_ou_encerrado_nao_se_descarregam'],
            'tests/Feature/Jobs/ComTenantTest.php' => ['test_tenant_nao_activo_descarta_o_job_e_regista'],
            'tests/Feature/Comandos/ConvencaoDeComandosTest.php' => ['test_tenant_suspenso_ou_encerrado_e_recusado', 'test_todos_corre_so_os_activos_e_avisa_dos_saltados'],
            'Modules/Autenticacao/tests/Feature/PodarTokensCommandTest.php' => ['test_todos_poda_cada_tenant_activo_e_salta_o_suspenso'],
        ],
    ];

    /** Módulo com models de tenant → o seu teste de tenancy. */
    private const TESTE_DE_TENANCY_POR_MODULO = [
        'Aluno' => 'Modules/Aluno/tests/Feature/AlunoTenancyTest.php',
        'AnoLectivo' => 'Modules/AnoLectivo/tests/Feature/AnoLectivoTenancyTest.php',
        'Autenticacao' => 'Modules/Autenticacao/tests/Feature/TokenTenancyTest.php',
        'Core' => 'Modules/Core/tests/Feature/HorarioTenancyTest.php',
        'Curso' => 'Modules/Curso/tests/Feature/CursoTenancyTest.php',
        'Disciplina' => 'Modules/Disciplina/tests/Feature/DisciplinaTenancyTest.php',
        'Estabelecimento' => 'Modules/Estabelecimento/tests/Feature/EstabelecimentoTenancyTest.php',
        'Infraestrutura' => 'Modules/Infraestrutura/tests/Feature/SalaTenancyTest.php',
        'Matricula' => 'Modules/Matricula/tests/Feature/MatriculaTenancyTest.php',
        'Permissao' => 'Modules/Permissao/tests/Feature/PermissaoTenancyTest.php',
        'PlanoCurricular' => 'Modules/PlanoCurricular/tests/Feature/PlanoCurricularTenancyTest.php',
        'Turma' => 'Modules/Turma/tests/Feature/TurmaTenancyTest.php',
        'Usuario' => 'Modules/Usuario/tests/Feature/UsuarioTenancyTest.php',
    ];

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    /** @return list<class-string<Model>> models concretos com PertenceAoTenant */
    private function modelsDeTenant(): array
    {
        $models = [];

        foreach ($this->classesDeModels() as $classe) {
            if (is_subclass_of($classe, Model::class)
                && ! (new ReflectionClass($classe))->isAbstract()
                && in_array(PertenceAoTenant::class, class_uses_recursive($classe), true)) {
                $models[] = $classe;
            }
        }

        return $models;
    }

    // ------------------------------------------------------------------ MAPA

    public function test_o_mapa_tem_todas_as_linhas_da_matriz(): void
    {
        $this->assertSame([
            'Listagem', 'ID directo / route model binding', 'exists', 'unique', 'Repository / Service', 'Action',
            'Sem contexto', 'Sequências', 'Cache', 'Ficheiros', 'Jobs', 'Commands', 'Autenticação', 'Consistência',
            'Estado do tenant',
        ], array_keys(self::MAPA));
    }

    public function test_cada_teste_referido_pelo_mapa_existe(): void
    {
        $erros = [];

        foreach (self::MAPA as $linha => $ficheiros) {
            $this->assertNotEmpty($ficheiros, $linha);

            foreach ($ficheiros as $ficheiro => $metodos) {
                $caminho = base_path($ficheiro);

                if (! is_file($caminho)) {
                    $erros[] = "{$linha}: o ficheiro {$ficheiro} não existe.";

                    continue;
                }

                $codigo = file_get_contents($caminho);

                foreach ($metodos as $metodo) {
                    if (preg_match('/function\s+' . preg_quote($metodo, '/') . '\s*\(/', $codigo) !== 1) {
                        $erros[] = "{$linha}: {$ficheiro} já não tem {$metodo}().";
                    } elseif (! $this->temAsserções($this->corpoDoMetodo($codigo, $metodo))) {
                        $erros[] = "{$linha}: {$ficheiro}::{$metodo}() não tem nenhuma asserção (teste esvaziado?).";
                    }
                }
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    /** Corpo do método até ao próximo método do mesmo nível de indentação. */
    private function corpoDoMetodo(string $codigo, string $metodo): string
    {
        $inicio = preg_match('/function\s+' . preg_quote($metodo, '/') . '\s*\(/', $codigo, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : 0;
        $resto = substr($codigo, $inicio + 1);
        $fim = preg_match('/\n    (?:public|private|protected)\s+(?:static\s+)?function\s/', $resto, $f, PREG_OFFSET_CAPTURE) === 1 ? $f[0][1] : strlen($resto);

        return substr($resto, 0, $fim);
    }

    private function temAsserções(string $corpo): bool
    {
        return preg_match('/assert|expectException|->fail\s*\(/i', $corpo) === 1;
    }

    /** @dataProvider exemplosDeCorpos */
    public function test_o_detector_de_testes_esvaziados_funciona(string $codigo, bool $esperado): void
    {
        $this->assertSame($esperado, $this->temAsserções($this->corpoDoMetodo($codigo, 'test_x')), $codigo);
    }

    public static function exemplosDeCorpos(): array
    {
        return [
            'com assert' => ["    public function test_x(): void\n    {\n        \$this->assertTrue(true);\n    }\n", true],
            'com assertX encadeado' => ["    public function test_x(): void\n    {\n        \$r->assertOk();\n    }\n", true],
            'com expectException' => ["    public function test_x(): void\n    {\n        \$this->expectException(E::class);\n    }\n", true],
            'esvaziado' => ["    public function test_x(): void\n    {\n        \$a = 1;\n    }\n\n    public function test_y(): void\n    {\n        \$this->assertTrue(true);\n    }\n", false],
        ];
    }

    public function test_cada_modulo_com_models_de_tenant_tem_o_seu_teste_de_tenancy(): void
    {
        $modulos = [];

        foreach ($this->modelsDeTenant() as $classe) {
            $modulos[explode('\\', $classe)[1]] = true;
        }

        $modulos = array_keys($modulos);
        sort($modulos);
        $declarados = array_keys(self::TESTE_DE_TENANCY_POR_MODULO);

        $this->assertSame($declarados, $modulos, 'Módulo novo com models de tenant: declare o seu teste de tenancy em TESTE_DE_TENANCY_POR_MODULO.');

        foreach (self::TESTE_DE_TENANCY_POR_MODULO as $modulo => $ficheiro) {
            $this->assertFileExists(base_path($ficheiro), "{$modulo}: falta o teste de tenancy.");
        }
    }

    // ------------------------------------------------- Sem contexto / Action / Listagem (todos os models)

    public function test_ha_models_de_tenant_para_analisar(): void
    {
        $this->assertGreaterThan(30, count($this->modelsDeTenant()));
    }

    public function test_sem_contexto_a_leitura_e_a_escrita_de_qualquer_model_lancam(): void
    {
        // Controlo positivo: com contexto, a leitura de cada model funciona.
        foreach ($this->modelsDeTenant() as $classe) {
            $this->assertIsInt($classe::query()->count(), $classe);
        }

        app(TenantContext::class)->limpar();

        foreach ($this->modelsDeTenant() as $classe) {
            try {
                $classe::query()->get();
                $this->fail("{$classe}: a leitura sem contexto não lançou.");
            } catch (TenantNaoResolvido) {
                $this->addToAssertionCount(1);
            }

            try {
                (new $classe())->forceFill([])->save();
                $this->fail("{$classe}: a escrita sem contexto não lançou.");
            } catch (TenantNaoResolvido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_consulta_de_qualquer_model_filtra_pelo_tenant_do_contexto(): void
    {
        foreach ([$this->tenant, $this->outro] as $tenant) {
            $this->noTenant($tenant, function () use ($tenant) {
                foreach ($this->modelsDeTenant() as $classe) {
                    $sql = $classe::query()->toRawSql();
                    $tabela = (new $classe())->getTable();

                    $this->assertMatchesRegularExpression(
                        '/"?' . preg_quote($tabela, '/') . '"?\."?tenant_id"?\s*=\s*' . $tenant->id . '\b/',
                        $sql,
                        "{$classe}: a consulta no tenant {$tenant->codigo} não filtra por tenant_id.",
                    );
                }
            });
        }
    }

    public function test_alterar_o_tenant_de_qualquer_model_lanca_excepcao(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->popularDadosAcademicos('AAA');
        User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => Hash::make('x')])->createToken('t');
        $verificados = 0;

        foreach ($this->modelsDeTenant() as $classe) {
            $registo = $classe::query()->first();

            if ($registo === null) {
                continue;
            }

            $verificados++;
            $registo->tenant_id = $this->outro->id;

            try {
                $registo->save();
                $this->fail("{$classe}: aceitou mudar o tenant_id.");
            } catch (AlteracaoDeTenantProibida) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertGreaterThan(15, $verificados, 'Poucos models com dados: o cenário deixou de popular o suficiente.');
    }

    // ------------------------------------------------------------- Repository / Service

    public function test_os_servicos_de_consulta_no_contexto_de_a_nao_devolvem_dados_de_b(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->popularDadosAcademicos('AAA');
        User::create(['name' => 'UtilizadorAAA', 'email' => 'aaa@example.com', 'password' => Hash::make('x')]);
        Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'PerfilAAA']);
        Horario::create(['nome' => 'HorarioAAA', 'hora_inicio' => '08:00', 'hora_fim' => '09:00']);

        $this->noTenant($this->outro, function () {
            $this->seed(PermissaoDatabaseSeeder::class);
            $this->popularDadosAcademicos('BBB');
            User::create(['name' => 'UtilizadorBBB', 'email' => 'bbb@example.com', 'password' => Hash::make('x')]);
            Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'PerfilBBB']);
            Horario::create(['nome' => 'HorarioBBB', 'hora_inicio' => '08:00', 'hora_fim' => '09:00']);
        });

        $servicos = [
            'Curso::listar' => fn () => app(CursoConsultaService::class)->listar(),
            'Disciplina::listar' => fn () => app(DisciplinaConsultaService::class)->listar(),
            'Sala::listar' => fn () => app(SalaConsultaService::class)->listar(),
            'AnoLectivo::listar' => fn () => app(AnoLectivoConsultaService::class)->listar(),
            'Horario::listar' => fn () => app(HorarioConsultaService::class)->listar(),
            'NivelAcademico::listar' => fn () => app(NivelAcademicoConsultaService::class)->listar(),
            'Turno::listar' => fn () => app(TurnoConsultaService::class)->listar(),
            'Turma::listar' => fn () => app(TurmaConsultaService::class)->listar(),
            'Turma::opcoesFormulario' => fn () => app(TurmaConsultaService::class)->opcoesFormulario(),
            'Turma::salasDisponiveis' => fn () => app(TurmaConsultaService::class)->salasDisponiveis(),
            'PlanoCurricular::opcoesFormulario' => fn () => app(PlanoCurricularConsultaService::class)->opcoesFormulario(),
            'Aluno::listar' => fn () => app(AlunoConsultaService::class)->listar(),
            'Aluno::cursosDisponiveis' => fn () => app(AlunoConsultaService::class)->cursosDisponiveis(),
            'Aluno::anosLectivosDisponiveis' => fn () => app(AlunoConsultaService::class)->anosLectivosDisponiveis(),
            'Matricula::listarTodas' => fn () => app(MatriculaConsultaService::class)->listarTodas(),
            'Matricula::anosLectivosDisponiveis' => fn () => app(MatriculaConsultaService::class)->anosLectivosDisponiveis(),
            'Usuario::listar' => fn () => app(UsuarioConsultaService::class)->listar(),
            'Permissao::listarPerfis' => fn () => app(PermissaoConsultaService::class)->listarPerfis(),
        ];

        foreach ([[$this->tenant, 'AAA', 'BBB'], [$this->outro, 'BBB', 'AAA']] as [$tenant, $minha, $alheia]) {
            $this->noTenant($tenant, function () use ($servicos, $minha, $alheia, $tenant) {
                foreach ($servicos as $nome => $chamar) {
                    $json = json_encode($chamar(), JSON_THROW_ON_ERROR);

                    $this->assertStringNotContainsString($alheia, $json, "{$nome} no tenant {$tenant->codigo}: apareceram dados do outro tenant.");

                    if (! str_contains($json, $minha)) {
                        $this->fail("{$nome} no tenant {$tenant->codigo}: não devolveu os dados do próprio tenant (controlo positivo).");
                    }
                }
            });
        }
    }

    // ------------------------------------------------------------------------ Cache

    public function test_a_cache_de_permissoes_usa_o_prefixo_do_tenant_na_loja_real(): void
    {
        app(PermissaoCache::class)->guardar(7, ['turmas.ver']);
        $this->noTenant($this->outro, fn () => app(PermissaoCache::class)->guardar(7, ['cursos.ver']));

        $chaveA = "tenant:{$this->tenant->id}:permissoes:v1:user:7";
        $chaveB = "tenant:{$this->outro->id}:permissoes:v1:user:7";

        $this->assertSame(['turmas.ver'], Cache::get($chaveA));
        $this->assertSame(['cursos.ver'], Cache::get($chaveB));
        $this->assertNull(Cache::get('permissoes:v1:user:7'), 'Nenhuma chave sem prefixo de tenant.');
    }

    public function test_esquecer_ou_invalidar_permissoes_em_a_nao_afecta_b(): void
    {
        app(PermissaoCache::class)->guardar(7, ['turmas.ver']);
        app(PermissaoCache::class)->guardar(8, ['turmas.ver']);
        $this->noTenant($this->outro, function () {
            app(PermissaoCache::class)->guardar(7, ['cursos.ver']);
            app(PermissaoCache::class)->guardar(8, ['cursos.ver']);
        });

        app(PermissaoCache::class)->esquecerUtilizador(7);

        $this->assertNull(app(PermissaoCache::class)->obter(7), 'Controlo positivo: esquecido em A.');
        $this->assertSame(['cursos.ver'], $this->noTenant($this->outro, fn () => app(PermissaoCache::class)->obter(7)), 'O mesmo id em B fica.');

        app(PermissaoCache::class)->invalidarTudo();

        $this->assertNull(app(PermissaoCache::class)->obter(8), 'Controlo positivo: invalidado em A.');
        $this->assertSame(['cursos.ver'], $this->noTenant($this->outro, fn () => app(PermissaoCache::class)->obter(8)), 'B fica intacto.');
    }

    // --------------------------------------------------- Ficheiros / Estado do tenant

    public function test_ficheiros_de_um_tenant_suspenso_ou_encerrado_nao_se_descarregam(): void
    {
        Storage::fake('privado');
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('segredo123')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);
        $aluno = app(CriarAlunoAction::class)->executar(new AlunoDTO(
            dadosPessoaId: null, nomeCompleto: 'Ana Silva', email: null, telefone: null,
            dataNascimento: '2010-05-01', sexo: 0, numeroIdentificacao: 'BI0001',
        ), UploadedFile::fake()->image('foto.jpg'));
        $url = $this->urlDoTenant($this->tenant, "/alunos/{$aluno->id}/foto");

        $this->actingAs($admin)->get($url)->assertOk();

        $this->tenant->update(['estado' => EstadoTenant::SUSPENSO]);
        $this->actingAs($admin)->get($url)->assertForbidden();

        $this->tenant->update(['estado' => EstadoTenant::ENCERRADO]);
        $this->actingAs($admin)->get($url)->assertNotFound();

        $this->tenant->update(['estado' => EstadoTenant::ACTIVO]);
        $this->actingAs($admin)->get($url)->assertOk();
    }

    public function test_o_disco_local_nao_regista_a_rota_storage_sem_autenticacao(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($rota) => $rota->uri())->all();

        $this->assertNotContains('storage/{path}', $uris, "O disco 'local' não pode servir ficheiros por URL (serve=false).");
        $this->assertFalse(config('filesystems.disks.local.serve'));
        // Controlo: o symlink do disco público (public/storage) não depende desta rota.
        $this->assertStringEndsWith('/storage', config('filesystems.disks.public.url'));
    }
}
