<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\AnoLectivo\Services\AnoLectivoConsultaService;
use Modules\Core\Models\Horario;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Core\Services\HorarioConsultaService;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Services\CursoConsultaService;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
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
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Services\GestaoDocumentoPessoaService;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\UsuarioConsultaService;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
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
            'tests/Feature/Tenancy/MatrizIsolamentoTest.php' => [
                'test_os_servicos_de_consulta_no_contexto_de_a_nao_devolvem_dados_de_b',
                'test_os_servicos_com_parametros_validos_de_a_nao_devolvem_dados_de_b',
                'test_os_servicos_com_parametros_ou_modelos_de_b_no_contexto_de_a_nao_devolvem_dados_de_b',
                'test_os_servicos_sem_parametros_ainda_nao_cobertos_so_devolvem_dados_do_proprio_tenant',
                'test_os_dados_de_apoio_dos_perfis_so_tem_os_perfis_do_tenant',
                'test_servir_foto_e_a_leitura_de_documentos_de_b_no_contexto_de_a_nao_servem_nada_de_b',
            ],
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
        'Plataforma / Super Admin' => [
            // Task 2: a fronteira entre mundos (host, contexto, sessão e rotas).
            'Modules/Plataforma/tests/Feature/FronteiraTest.php' => [
                'test_host_central_serve_a_plataforma_e_nao_a_escola', 'test_no_modo_unico_o_host_central_e_do_painel_e_qualquer_outro_host_e_da_escola', 'test_host_central_nunca_abre_contexto', 'test_leitura_tenant_scoped_no_painel_falha_alto',
                'test_cookie_da_plataforma_e_proprio', 'test_sessao_da_plataforma_nao_vale_na_escola_nem_vice_versa', 'test_sessions_user_id_fica_nulo_para_a_plataforma',
            ],
            'Modules/Plataforma/tests/Feature/FronteiraRotasTest.php' => [
                'test_nenhuma_rota_plataforma_tem_middleware_de_tenancy_e_todas_comecam_por_apenas_host_central',
                'test_as_rotas_autenticadas_tem_a_cadeia_de_autenticacao_pela_ordem_e_as_publicas_sao_so_o_login',
            ],
            // Task 3: autenticação, sessões e CSRF do painel.
            'Modules/Plataforma/tests/Feature/AutenticacaoPlataformaTest.php' => [
                'test_um_utilizador_de_escola_nao_passa_em_auth_plataforma', 'test_um_super_admin_nao_passa_na_escola',
                'test_a_sessao_de_escola_com_o_mesmo_id_numerico_nao_e_afectada_e_vice_versa', 'test_pedido_json_de_conta_desactivada_da_401_json_e_web_e_inertia_continuam_a_redireccionar',
            ],
            // Task 5: revogar acessos só toca na escola alvo.
            'Modules/Plataforma/tests/Feature/CicloDeVidaTest.php' => ['test_revogar_acessos_apaga_so_as_sessoes_e_tokens_da_escola_alvo_e_deixa_as_da_plataforma_e_de_outra_escola'],
            // Task 6: recuperar o administrador abre o contexto só dentro da Action e só lê nome e e-mail.
            'Modules/Plataforma/tests/Feature/RecuperarAdministradorTest.php' => [
                'test_o_pedido_de_listar_corre_com_contexto_vazio_e_so_o_contrato_o_abre', 'test_so_o_administrador_alvo_e_afectado_e_a_outra_escola_fica_intacta',
                'test_nenhuma_resposta_do_painel_leva_dados_de_escola_alem_do_nome_e_email_do_administrador',
            ],
            'Modules/Autenticacao/tests/Feature/RecuperaAdministradorDoTenantTest.php' => ['test_o_dto_so_tem_nome_e_email'],
            // Task 7: ponta a ponta, espião do contexto e varrimento de dados académicos.
            'tests/Feature/Tenancy/PlataformaIsolamentoTest.php' => [
                'test_o_ciclo_completo_no_painel_nunca_abre_contexto_e_b_nao_ve_alteracoes_de_a', 'test_os_dados_de_escola_nunca_chegam_ao_painel_nem_por_erros_ou_404',
                'test_o_espiao_falha_se_um_controller_da_plataforma_operar_o_contexto', 'test_uma_violacao_engolida_por_um_catch_falha_na_verificacao_final', 'test_depois_de_um_mundo_o_store_nao_vaza_para_o_outro',
            ],
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
        'Financeiro' => 'Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php',
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
            'Estado do tenant', 'Plataforma / Super Admin',
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


    // ----------------------------------- Repository / Service: métodos com parâmetros

    /**
     * Um mundo completo de uma escola (marcador `$m`), no contexto actual: dados académicos, utilizador com
     * perfil, perfil personalizado, horário, histórico e inscrição de matrícula e uma turma ligada ao curso
     * (para o plano curricular resolver). Devolve os modelos para usar como parâmetros.
     *
     * @return array<string, mixed>
     */
    private function mundoParaServicos(string $m): array
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $d = $this->popularDadosAcademicos($m);

        // A turma passa a ter o curso do plano: só assim o plano curricular da matrícula é resolvido.
        $d['turma']->forceFill(['curso_id' => $d['curso']->id])->save();

        // Relações carregadas já no mundo de origem: levadas para o contexto do outro tenant, chegam aos serviços tal como são.
        $d['pessoa'] = $d['aluno']->dadosPessoa;
        $d['matricula']->load('turma');

        $d['utilizador'] = User::create(['name' => "Utilizador{$m}", 'email' => strtolower($m) . '@example.com', 'password' => Hash::make('x')]);
        $d['utilizador']->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);
        $d['perfil'] = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => "Perfil{$m}"]);
        $d['horario'] = Horario::create(['nome' => "Horario{$m}", 'hora_inicio' => '08:00', 'hora_fim' => '09:00']);
        $d['historico'] = MatriculaHistorico::create([
            'matricula_id' => $d['matricula']->id, 'estado_anterior' => EstadoMatriculaEnum::PENDENTE,
            'estado_novo' => EstadoMatriculaEnum::ACTIVA, 'utilizador_id' => $d['utilizador']->id,
        ]);
        // Desistida: aparece na lista da matrícula mas não tira a disciplina das "disponíveis para inscrição".
        $d['inscricao'] = InscricaoDisciplina::create([
            'matricula_id' => $d['matricula']->id,
            'plano_curricular_disciplina_id' => PlanoCurricularDisciplina::query()->firstOrFail()->id,
            'data_inscricao' => '2026-02-02', 'estado' => EstadoInscricaoDisciplinaEnum::DESISTIDA,
        ]);

        return $d;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} os mundos de A e de B */
    private function doisMundosParaServicos(): array
    {
        $a = $this->mundoParaServicos('AAA');
        $b = $this->noTenant($this->outro, fn () => $this->mundoParaServicos('BBB'));

        return [$a, $b];
    }

    /**
     * Métodos públicos de leitura dos *ConsultaService (e do GestaoDocumentoPessoaService) que recebem
     * parâmetros. Cada entrada: [chamar(mundo), temDadosProprios(resultado)?, vazioComParametrosAlheios].
     * `chamar` devolve só o que vem da base de dados (nunca o próprio modelo recebido), por isso um
     * parâmetro de B no contexto de A tem de dar vazio. Sem `temDadosProprios`, o controlo positivo é
     * "o resultado contém o marcador do tenant".
     *
     * @return array<string, array{0: callable, 1?: ?callable, 2?: bool}>
     */
    private function chamadasComParametros(): array
    {
        $curso = app(CursoConsultaService::class);
        $disciplina = app(DisciplinaConsultaService::class);
        $nivel = app(NivelAcademicoConsultaService::class);
        $turno = app(TurnoConsultaService::class);
        $turma = app(TurmaConsultaService::class);
        $ano = app(AnoLectivoConsultaService::class);
        $aluno = app(AlunoConsultaService::class);
        $matricula = app(MatriculaConsultaService::class);
        $usuario = app(UsuarioConsultaService::class);
        $permissao = app(PermissaoConsultaService::class);
        $documentos = app(GestaoDocumentoPessoaService::class);

        return [
            // Pesquisas por texto comum aos dois tenants ("Curso" casa CursoAAA e CursoBBB): só o próprio sai.
            'Curso::listar(pesquisa)' => [fn (array $d) => $curso->listar(['pesquisa' => 'Curso']), null, false],
            'Curso::listar(pesquisa+estado)' => [fn (array $d) => $curso->listar(['pesquisa' => 'Curso', 'estado' => '1']), null, false],
            'Disciplina::listar(pesquisa)' => [fn (array $d) => $disciplina->listar(['pesquisa' => 'Disciplina']), null, false],
            'NivelAcademico::listar(pesquisa+etapa)' => [fn (array $d) => $nivel->listar(['pesquisa' => 'Nivel', 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO->value]), null, false],
            'Turno::listar(pesquisa)' => [fn (array $d) => $turno->listar(['pesquisa' => 'Turno']), null, false],
            'Turma::listar(pesquisa)' => [fn (array $d) => $turma->listar(['pesquisa' => 'Turma']), null, false],
            // Filtros por id: com ids do próprio tenant devolvem o próprio; com ids de B, vazio.
            'Turma::listar(ids)' => [fn (array $d) => $turma->listar([
                'ano_lectivo_id' => $d['ano']->id, 'curso_id' => $d['curso']->id, 'nivel_academico_id' => $d['nivel']->id, 'turno_id' => $d['turno']->id,
            ])],
            'Aluno::listar(pesquisa)' => [fn (array $d) => $aluno->listar(['pesquisa' => 'Aluno']), null, false],
            'Aluno::listar(ids)' => [fn (array $d) => $aluno->listar([
                'ano_lectivo_id' => $d['ano']->id, 'turma_id' => $d['turma']->id, 'curso_id' => $d['curso']->id, 'nivel_academico_id' => $d['nivel']->id,
            ])],
            'Aluno::turmasDisponiveis(ano)' => [fn (array $d) => $aluno->turmasDisponiveis(['ano_lectivo_id' => $d['ano']->id])],
            'Aluno::turmasDisponiveis()' => [fn (array $d) => $aluno->turmasDisponiveis([]), null, false],
            'Matricula::listarTodas(pesquisa)' => [fn (array $d) => $matricula->listarTodas(['pesquisa' => 'Aluno']), null, false],
            'Matricula::listarTodas(ids+estado)' => [fn (array $d) => $matricula->listarTodas([
                'turma_id' => $d['turma']->id, 'ano_lectivo_id' => $d['ano']->id, 'estado' => EstadoMatriculaEnum::PENDENTE->value,
            ])],
            'Usuario::listar(pesquisa)' => [fn (array $d) => $usuario->listar(null, ['pesquisa' => 'Utilizador']), null, false],
            'Usuario::listar(perfil)' => [fn (array $d) => $usuario->listar(Perfil::ADMIN_ESCOLA, ['estado' => '1']), null, false],

            // Modelos como parâmetro: um modelo de B no contexto de A não pode carregar nada de B.
            'Curso::comRelacoes' => [fn (array $d) => $curso->comRelacoes($d['curso'])->planosCurriculares],
            'NivelAcademico::comRelacoes' => [fn (array $d) => $nivel->comRelacoes($d['nivel'])->planosCurriculares],
            'Turno::comRelacoes' => [fn (array $d) => $turno->comRelacoes($d['turno'])->turmas],
            'AnoLectivo::comRelacoes' => [fn (array $d) => $ano->comRelacoes($d['ano'])->periodos],
            'Matricula::listarPorAluno' => [fn (array $d) => $matricula->listarPorAluno($d['aluno'], ['pesquisa' => 'REG', 'ano_lectivo_id' => $d['ano']->id])],
            'Matricula::matriculasActivasNoAnoLectivo' => [fn (array $d) => $matricula->matriculasActivasNoAnoLectivo($d['aluno'])],
            'Matricula::matriculaActual' => [fn (array $d) => $matricula->matriculaActual($d['aluno']), null],
            'Matricula::ultimasMatriculas' => [fn (array $d) => $matricula->ultimasMatriculas($d['aluno'])],
            'Matricula::anosLectivosComMatricula' => [fn (array $d) => $matricula->anosLectivosComMatricula($d['aluno'])],
            'Matricula::historicoDaMatricula' => [
                fn (array $d) => $matricula->historicoDaMatricula($d['matricula'])->map(fn (MatriculaHistorico $h) => $h->matricula->numero_registo_matricula),
            ],
            'Matricula::listarDisciplinasDaMatricula' => [fn (array $d) => $matricula->listarDisciplinasDaMatricula($d['matricula'])],
            'Matricula::disciplinasDisponiveisParaInscricao' => [fn (array $d) => $matricula->disciplinasDisponiveisParaInscricao($d['matricula'])],
            'Usuario::dadosParaEdicao' => [
                fn (array $d) => Arr::only($usuario->dadosParaEdicao($d['utilizador']), ['perfil', 'celulas']),
                fn ($r, array $d) => $r['perfil'] === Perfil::ADMIN_ESCOLA->slug(),
            ],
            'Permissao::dadosPermissoesDoPerfil' => [fn (array $d) => Arr::only($permissao->dadosPermissoesDoPerfil($d['perfil']), ['marcadas']), fn ($r, array $d) => true, true],
            'Permissao::dadosPermissoesDoUtilizador(perfisAtribuidos)' => [
                fn (array $d) => Arr::only($permissao->dadosPermissoesDoUtilizador($d['utilizador']), ['perfisAtribuidos', 'permitidasPeloPerfil', 'overrides']),
                fn ($r, array $d) => $r['perfisAtribuidos']->contains(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->value('id')),
            ],
            'Permissao::dadosPermissoesDoUtilizador(perfis)' => [fn (array $d) => Arr::only($permissao->dadosPermissoesDoUtilizador($d['utilizador']), ['perfis']), null, false],
            'GestaoDocumentoPessoa::listar' => [fn (array $d) => $documentos->listar($d['pessoa'])],
            'GestaoDocumentoPessoa::listarInativos' => [fn (array $d) => $documentos->listarInativos($d['pessoa'])],
        ];
    }

    private function estaVazio(mixed $resultado): bool
    {
        return match (true) {
            $resultado === null => true,
            $resultado instanceof LengthAwarePaginator => $resultado->total() === 0,
            $resultado instanceof Collection => $resultado->isEmpty(),
            is_array($resultado) => collect($resultado)->every(fn ($valor) => $this->estaVazio($valor)),
            default => false,
        };
    }

    /** Documentos (um activo e um inactivo) com o marcador no nome, para o controlo positivo do GestaoDocumentoPessoaService. */
    private function documentosDoMundo(array $d, string $m): void
    {
        $tipo = TipoDocumento::create(['nome' => "Tipo{$m}", 'slug' => 'tipo-' . strtolower($m)]);

        foreach ([1 => 'Activo', 0 => 'Inactivo'] as $estado => $rotulo) {
            DocumentoPessoa::create([
                'dados_pessoa_id' => $d['aluno']->dados_pessoa_id, 'tipo_documento_id' => $tipo->id, 'numero_documento' => "N{$estado}{$m}",
                'nome_original' => "Doc{$rotulo}{$m}.pdf", 'caminho' => CaminhoTenant::para("documentos/{$estado}-{$m}.pdf"), 'mime_type' => 'application/pdf', 'tamanho' => 1, 'estado' => $estado,
            ]);
        }
    }

    public function test_os_servicos_com_parametros_validos_de_a_nao_devolvem_dados_de_b(): void
    {
        [$a, $b] = $this->doisMundosParaServicos();
        $this->documentosDoMundo($a, 'AAA');
        $this->noTenant($this->outro, fn () => $this->documentosDoMundo($b, 'BBB'));
        $chamadas = $this->chamadasComParametros();

        foreach ([[$this->tenant, $a, 'AAA', 'BBB'], [$this->outro, $b, 'BBB', 'AAA']] as [$tenant, $mundo, $minha, $alheia]) {
            $this->noTenant($tenant, function () use ($chamadas, $mundo, $minha, $alheia, $tenant) {
                foreach ($chamadas as $nome => $entrada) {
                    $resultado = $entrada[0]($mundo);
                    $json = json_encode($resultado, JSON_THROW_ON_ERROR);

                    $this->assertStringNotContainsString($alheia, $json, "{$nome} no tenant {$tenant->codigo}: apareceram dados do outro tenant.");

                    $temProprios = ($entrada[1] ?? null) !== null
                        ? $entrada[1]($resultado, $mundo)
                        : str_contains($json, $minha);
                    $this->assertTrue($temProprios, "{$nome} no tenant {$tenant->codigo}: não devolveu os dados do próprio tenant (controlo positivo).");
                }
            });
        }
    }

    public function test_os_servicos_com_parametros_ou_modelos_de_b_no_contexto_de_a_nao_devolvem_dados_de_b(): void
    {
        [$a, $b] = $this->doisMundosParaServicos();
        $this->documentosDoMundo($a, 'AAA');
        $this->noTenant($this->outro, fn () => $this->documentosDoMundo($b, 'BBB'));
        $chamadas = $this->chamadasComParametros();
        $comExcepcao = [];

        foreach ([[$this->tenant, $b, 'BBB', 'AAA'], [$this->outro, $a, 'AAA', 'BBB']] as [$tenant, $alheio, $alheia, $minha]) {
            $this->noTenant($tenant, function () use ($chamadas, $alheio, $alheia, $tenant, &$comExcepcao) {
                foreach ($chamadas as $nome => $entrada) {
                    try {
                        $resultado = $entrada[0]($alheio);
                    } catch (Throwable $e) {
                        // Uma excepção (404, tipo errado) é uma resposta aceitável: não devolveu dados do outro tenant.
                        $comExcepcao[$nome] = $e::class;

                        continue;
                    }

                    $this->assertStringNotContainsString($alheia, json_encode($resultado, JSON_THROW_ON_ERROR), "{$nome} no tenant {$tenant->codigo} com parâmetros do outro: vazaram dados.");

                    if ($entrada[2] ?? true) {
                        $this->assertTrue($this->estaVazio($resultado), "{$nome} no tenant {$tenant->codigo} com parâmetros do outro: devia dar vazio.");
                    }
                }
            });
        }

        // Controlo: o cenário continua a exercitar os métodos (a maioria responde vazio, não com excepção).
        $this->assertLessThan(count($chamadas), count($comExcepcao), 'Demasiadas chamadas a lançar: o cenário deixou de provar o "vazio".');
    }

    public function test_os_servicos_sem_parametros_ainda_nao_cobertos_so_devolvem_dados_do_proprio_tenant(): void
    {
        [$a, $b] = $this->doisMundosParaServicos();
        $chamadas = [
            'Turno::horariosDisponiveis' => fn () => app(TurnoConsultaService::class)->horariosDisponiveis(),
            'Curso::niveisAcademicos' => fn () => app(CursoConsultaService::class)->niveisAcademicos(),
            'Aluno::niveisAcademicosDisponiveis' => fn () => app(AlunoConsultaService::class)->niveisAcademicosDisponiveis(),
            'Matricula::turmasDisponiveis' => fn () => app(MatriculaConsultaService::class)->turmasDisponiveis(),
            'Permissao::listarPerfis' => fn () => app(PermissaoConsultaService::class)->listarPerfis(),
        ];

        foreach ([[$this->tenant, 'AAA', 'BBB'], [$this->outro, 'BBB', 'AAA']] as [$tenant, $minha, $alheia]) {
            $this->noTenant($tenant, function () use ($chamadas, $minha, $alheia, $tenant) {
                foreach ($chamadas as $nome => $chamar) {
                    $json = json_encode($chamar(), JSON_THROW_ON_ERROR);

                    $this->assertStringNotContainsString($alheia, $json, "{$nome} no tenant {$tenant->codigo}: apareceram dados do outro tenant.");
                    $this->assertStringContainsString($minha, $json, "{$nome} no tenant {$tenant->codigo}: não devolveu os dados do próprio tenant (controlo positivo).");
                }
            });
        }
    }

    public function test_os_dados_de_apoio_dos_perfis_so_tem_os_perfis_do_tenant(): void
    {
        $this->doisMundosParaServicos();
        $idsDe = fn (Tenant $tenant) => $this->noTenant($tenant, fn () => Role::query()->pluck('id')->all());
        $idsA = $idsDe($this->tenant);
        $idsB = $idsDe($this->outro);
        $this->assertNotEmpty($idsA);
        $this->assertSame([], array_intersect($idsA, $idsB), 'Pré-condição: os perfis de cada tenant têm ids próprios.');

        foreach ([[$this->tenant, $idsA, $idsB], [$this->outro, $idsB, $idsA]] as [$tenant, $meus, $alheios]) {
            $this->noTenant($tenant, function () use ($meus, $alheios, $tenant) {
                $apoio = app(UsuarioConsultaService::class)->dadosDeApoio();
                $ids = collect($apoio['perfis'])->pluck('id')->filter()->all();

                $this->assertCount(count(Perfil::cases()), $ids, "Tenant {$tenant->codigo}: um perfil do sistema ficou sem id (controlo positivo).");
                $this->assertSame([], array_diff($ids, $meus), 'Só ids de perfis do próprio tenant.');
                $this->assertSame([], array_intersect($ids, $alheios));
                $this->assertSame([], array_diff(array_keys($apoio['permissoesPorPerfil']->all()), $meus), 'As permissões por perfil só dizem respeito a perfis do próprio tenant.');
            });
        }
    }

    public function test_servir_foto_e_a_leitura_de_documentos_de_b_no_contexto_de_a_nao_servem_nada_de_b(): void
    {
        Storage::fake('privado');
        [$a, $b] = $this->doisMundosParaServicos();
        $this->documentosDoMundo($a, 'AAA');
        $this->noTenant($this->outro, fn () => $this->documentosDoMundo($b, 'BBB'));

        foreach ([[$this->tenant, $a], [$this->outro, $b]] as [$tenant, $mundo]) {
            $this->noTenant($tenant, function () use ($mundo) {
                $caminho = CaminhoTenant::para('alunos/fotos/foto.jpg');
                Storage::disk('privado')->put($caminho, 'conteudo');
                $mundo['aluno']->forceFill(['foto_path' => $caminho])->save();
            });
        }

        // Controlo positivo: cada um serve a sua foto no próprio contexto.
        foreach ([[$this->tenant, $a], [$this->outro, $b]] as [$tenant, $mundo]) {
            $this->noTenant($tenant, fn () => $this->assertSame(200, app(AlunoConsultaService::class)->servirFoto($mundo['aluno'])->getStatusCode()));
        }

        // Controlo negativo: o aluno de B no contexto de A (e vice-versa) é 404, mesmo com a foto de B no disco.
        foreach ([[$this->tenant, $b], [$this->outro, $a]] as [$tenant, $alheio]) {
            $this->noTenant($tenant, function () use ($alheio) {
                try {
                    app(AlunoConsultaService::class)->servirFoto($alheio['aluno']);
                    $this->fail('Serviu a foto de outro tenant.');
                } catch (HttpException $e) {
                    $this->assertSame(404, $e->getStatusCode());
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
