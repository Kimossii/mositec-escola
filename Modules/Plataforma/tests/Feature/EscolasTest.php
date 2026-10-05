<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Provisioning\CredencialInicial;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Tenant\Actions\CriarTenantAction;
use Modules\Tenant\DTO\CriarTenantDTO;
use Modules\Tenant\DTO\TenantCriado;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Escolas no painel: listagem, detalhe e criação. O painel corre sem contexto de tenant e só lê
 * `tenants`, `domains`, `super_admins` e `plataforma_auditoria`. As regras de domínio, código e
 * e-mail vêm da CriarTenantAction; o painel só mostra os erros que ela devolve.
 */
class EscolasTest extends TestCase
{
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private const ESCOLA = 'escola.mositec.test';

    private SuperAdmin $admin;

    private string $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        // Catálogo global que o provisioning da escola exige (módulos e acções).
        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        config(['tenancy.hosts_centrais' => [self::CENTRAL]]);
        // Sessões na BD (como em produção): permite afirmar o que fica gravado no payload.
        config(['session.driver' => 'database']);
        // Sem a lotaria de limpeza de sessões (2 em 100): acrescentaria um `delete from "sessions"` às
        // vezes e tornaria instáveis os testes que contam consultas.
        config(['session.lottery' => [0, 100]]);
        $this->admin = $this->superAdmin();
        $this->sessao = $this->entrarNoPainel($this->admin);
    }

    // --- Auxiliares --------------------------------------------------------------------------

    /** Escola só com registo de gestão (sem dados de escola): chega para a listagem e o detalhe. */
    private function escola(string $codigo, string $nome, string $dominio, EstadoTenant $estado = EstadoTenant::ACTIVO, array $extra = []): Tenant
    {
        $tenant = Tenant::create(['codigo' => $codigo, 'nome' => $nome, ...$extra]);
        $tenant->forceFill(['estado' => $estado])->save();
        $tenant->dominios()->create(['dominio' => $dominio, 'is_principal' => true]);

        return $tenant->fresh();
    }

    private function inertiaGet(string $caminho): TestResponse
    {
        return $this->noPainel('GET', $caminho, $this->sessao, cabecalhos: $this->cabecalhosInertia());
    }

    /** @return array<string, string> */
    private function formulario(array $sobrepor = []): array
    {
        return [
            'nome' => 'Escola Nova do Lubango',
            'admin_nome' => 'Maria Directora',
            'admin_email' => 'maria@escola-nova.test',
            'dominio' => 'lubango.mositec.test',
            'codigo' => '',
            ...$sobrepor,
        ];
    }

    /** @return int[] contagens das tabelas que a criação de uma escola toca */
    private function contagens(): array
    {
        return collect(['tenants', 'domains', 'estabelecimentos', 'roles', 'users'])
            ->filter(fn (string $tabela) => Schema::hasTable($tabela))
            ->mapWithKeys(fn (string $tabela) => [$tabela => DB::table($tabela)->count()])
            ->all();
    }

    /** @return string[] tabelas referidas pelas consultas registadas */
    private function tabelasConsultadas(): array
    {
        $tabelas = [];
        foreach (DB::getQueryLog() as $consulta) {
            preg_match_all('/\b(?:from|join|into|update)\s+["`\[]?(\w+)["`\]]?/i', $consulta['query'], $achados);
            array_push($tabelas, ...$achados[1]);
        }

        return array_values(array_unique($tabelas));
    }

    private function afirmarSoTabelasGlobais(): void
    {
        $permitidas = ['tenants', 'domains', 'super_admins', 'plataforma_auditoria', 'sessions', 'cache', 'cache_locks'];
        $consultadas = $this->tabelasConsultadas();

        $this->assertNotEmpty($consultadas, 'O registo de consultas está vazio: a asserção seria vácua.');
        $this->assertSame([], array_values(array_diff($consultadas, $permitidas)), 'Consulta a tabela fora das globais: ' . implode(', ', $consultadas));
    }

    // --- Listagem ----------------------------------------------------------------------------

    public function test_a_listagem_mostra_todos_os_tenants_com_estado_e_dominio_principal(): void
    {
        $a = $this->escola('MOSI-000101', 'Escola Alfa', 'alfa.mositec.test');
        $this->escola('MOSI-000102', 'Escola Beta', 'beta.mositec.test', EstadoTenant::SUSPENSO);
        $this->escola('MOSI-000103', 'Escola Gama', 'gama.mositec.test', EstadoTenant::ENCERRADO);
        // Domínio secundário criado DEPOIS do principal: a listagem mostra sempre o principal.
        $a->dominios()->create(['dominio' => 'alfa-extra.mositec.test', 'is_principal' => false]);

        $resposta = $this->inertiaGet('/plataforma/escolas');

        $resposta->assertOk();
        $this->assertSame('Plataforma/Escolas/Index', $resposta->json('component'));
        $porCodigo = collect($resposta->json('props.escolas.data'))->keyBy('codigo');
        // Mais a escola de teste por omissão (MOSI-000001, localhost).
        $this->assertEqualsCanonicalizing(['MOSI-000001', 'MOSI-000101', 'MOSI-000102', 'MOSI-000103'], $porCodigo->keys()->all());
        $this->assertSame('Escola Alfa', $porCodigo['MOSI-000101']['nome']);
        $this->assertSame(1, $porCodigo['MOSI-000101']['estado']);
        $this->assertSame(2, $porCodigo['MOSI-000102']['estado']);
        $this->assertSame(3, $porCodigo['MOSI-000103']['estado']);
        $this->assertSame('alfa.mositec.test', $porCodigo['MOSI-000101']['dominio_principal']);
        $this->assertSame('gama.mositec.test', $porCodigo['MOSI-000103']['dominio_principal']);
        $this->assertNotEmpty($porCodigo['MOSI-000101']['created_at']);
    }

    public function test_a_listagem_filtra_por_estado(): void
    {
        $this->escola('MOSI-000101', 'Escola Alfa', 'alfa.mositec.test');
        $this->escola('MOSI-000102', 'Escola Beta', 'beta.mositec.test', EstadoTenant::SUSPENSO);
        $this->escola('MOSI-000103', 'Escola Gama', 'gama.mositec.test', EstadoTenant::ENCERRADO);

        $codigos = fn (string $query) => collect($this->inertiaGet('/plataforma/escolas' . $query)->json('props.escolas.data'))->pluck('codigo')->all();

        $this->assertSame(['MOSI-000102'], $codigos('?estado=2'));
        $this->assertSame(['MOSI-000103'], $codigos('?estado=3'));
        $this->assertEqualsCanonicalizing(['MOSI-000001', 'MOSI-000101'], $codigos('?estado=1'));
        // Valor desconhecido ou vazio: sem filtro (nunca erro).
        $this->assertCount(4, $codigos('?estado=9'));
        $this->assertCount(4, $codigos('?estado='));
        $this->assertSame('2', (string) $this->inertiaGet('/plataforma/escolas?estado=2')->json('props.filtros.estado'));
    }

    public function test_a_listagem_pesquisa_por_nome_codigo_e_dominio(): void
    {
        $this->escola('MOSI-000201', 'Colégio Horizonte', 'horizonte.mositec.test');
        $this->escola('MOSI-000202', 'Instituto Aurora', 'aurora-ensino.mositec.test');
        $this->escola('MOSI-000203', 'Escola Estrela', 'estrela.mositec.test');
        $codigos = fn (string $pesquisa) => collect($this->inertiaGet('/plataforma/escolas?pesquisa=' . urlencode($pesquisa))->json('props.escolas.data'))->pluck('codigo')->all();

        $this->assertSame(['MOSI-000201'], $codigos('horiz'), 'por nome (parcial, sem distinguir maiúsculas)');
        $this->assertSame(['MOSI-000202'], $codigos('AURORA'), 'por nome');
        $this->assertSame(['MOSI-000203'], $codigos('MOSI-000203'), 'por código');
        $this->assertSame(['MOSI-000203'], $codigos('000203'), 'por parte do código');
        $this->assertSame(['MOSI-000202'], $codigos('aurora-ensino'), 'por domínio');
        $this->assertSame(['MOSI-000201'], $codigos('  horizonte.mositec  '), 'por domínio, com espaços à volta');
        $this->assertSame([], $codigos('nao-existe'));
        // Os curingas do LIKE são texto: "%" e "_" não devolvem tudo.
        $this->assertSame([], $codigos('%'));
        $this->assertSame([], $codigos('_'));
    }

    public function test_a_pesquisa_por_dominio_acha_dominios_secundarios_sem_duplicar_linhas(): void
    {
        $escola = $this->escola('MOSI-000201', 'Colégio Horizonte', 'horizonte.mositec.test');
        $escola->dominios()->create(['dominio' => 'horizonte-antigo.mositec.test', 'is_principal' => false]);

        $dados = $this->inertiaGet('/plataforma/escolas?pesquisa=horizonte')->json('props.escolas.data');

        $this->assertCount(1, $dados, 'Dois domínios a coincidir não duplicam a escola.');
        $this->assertSame('horizonte.mositec.test', $dados[0]['dominio_principal']);
    }

    public function test_a_listagem_pagina_e_mantem_os_filtros_nas_ligacoes(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            $this->escola(sprintf('MOSI-%06d', 300 + $i), "Escola Paginada {$i}", "p{$i}.mositec.test", EstadoTenant::SUSPENSO);
        }

        $primeira = $this->inertiaGet('/plataforma/escolas?estado=2');
        $segunda = $this->inertiaGet('/plataforma/escolas?estado=2&page=2');

        $this->assertCount(15, $primeira->json('props.escolas.data'));
        $this->assertSame(17, $primeira->json('props.escolas.total'));
        $this->assertCount(2, $segunda->json('props.escolas.data'));
        $this->assertSame(2, $segunda->json('props.escolas.current_page'));
        $this->assertSame([], array_values(array_intersect(
            array_column($primeira->json('props.escolas.data'), 'codigo'),
            array_column($segunda->json('props.escolas.data'), 'codigo'),
        )));
        foreach ($primeira->json('props.escolas.links') as $ligacao) {
            if ($ligacao['url'] !== null) {
                $this->assertStringContainsString('estado=2', $ligacao['url'], 'Os filtros têm de sobreviver à paginação.');
            }
        }
    }

    public function test_a_listagem_nao_tem_n_mais_1(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->escola(sprintf('MOSI-%06d', 400 + $i), "Escola {$i}", "n{$i}.mositec.test");
        }
        $consultas = $this->consultasAGestao();

        for ($i = 11; $i <= 20; $i++) {
            $this->escola(sprintf('MOSI-%06d', 400 + $i), "Escola {$i}", "n{$i}.mositec.test");
        }

        $this->assertSame($consultas, $this->consultasAGestao(), 'O número de consultas a tenants/domains não pode crescer com o número de escolas.');
    }

    /** Número de consultas da listagem a `tenants` e `domains` (as de sessão e afins não contam para o N+1). */
    private function consultasAGestao(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->inertiaGet('/plataforma/escolas')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter($log, fn (array $q) => preg_match('/\b(from|join|into|update)\s+"?(tenants|domains)"?/i', $q['query']) === 1));
    }

    public function test_a_listagem_e_o_detalhe_so_consultam_tabelas_globais(): void
    {
        $escola = $this->escola('MOSI-000101', 'Escola Alfa', 'alfa.mositec.test');
        // Dados de escola existem: o painel não pode lê-los (nem contá-los).
        $this->criarTenant('MOSI-000102', 'Escola Com Dados', 'dados.mositec.test');

        foreach (['/plataforma/escolas', '/plataforma/escolas?estado=1&pesquisa=alfa', '/plataforma/escolas/' . $escola->codigo, '/plataforma/escolas/nova'] as $caminho) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->inertiaGet($caminho)->assertOk();
            $this->afirmarSoTabelasGlobais();
            DB::disableQueryLog();
        }
    }

    public function test_a_listagem_nao_expoe_dados_de_escola(): void
    {
        $this->criarTenant('MOSI-000102', 'Escola Com Dados', 'dados.mositec.test');

        $props = $this->inertiaGet('/plataforma/escolas')->json('props.escolas.data.0');

        $this->assertEqualsCanonicalizing(['codigo', 'nome', 'estado', 'dominio_principal', 'created_at'], array_keys($props));
    }

    // --- Detalhe -----------------------------------------------------------------------------

    public function test_o_detalhe_mostra_nome_codigo_estado_dominios_e_actividade(): void
    {
        $escola = $this->escola('MOSI-000101', 'Escola Alfa', 'alfa.mositec.test');
        // Secundário criado ANTES de outro secundário e DEPOIS do principal: o principal vem sempre primeiro.
        $escola->dominios()->create(['dominio' => 'zeta.mositec.test', 'is_principal' => false]);
        $escola->dominios()->create(['dominio' => 'beta.mositec.test', 'is_principal' => false]);
        RegistoDeAuditoria::create(['super_admin_id' => $this->admin->id, 'accao' => 'escola.criada', 'codigo_tenant' => 'MOSI-000101', 'detalhe' => ['dominio' => 'alfa.mositec.test'], 'ip' => '10.0.0.1']);
        RegistoDeAuditoria::create(['super_admin_id' => null, 'accao' => 'escola.suspensa', 'codigo_tenant' => 'MOSI-000999']);

        $resposta = $this->inertiaGet('/plataforma/escolas/MOSI-000101');

        $resposta->assertOk();
        $this->assertSame('Plataforma/Escolas/Show', $resposta->json('component'));
        $dados = $resposta->json('props.escola');
        $this->assertSame('MOSI-000101', $dados['codigo']);
        $this->assertSame('Escola Alfa', $dados['nome']);
        $this->assertSame(1, $dados['estado']);
        $this->assertNull($dados['motivo_suspensao']);
        $this->assertSame(['alfa.mositec.test', 'beta.mositec.test', 'zeta.mositec.test'], array_column($dados['dominios'], 'dominio'));
        $this->assertSame([true, false, false], array_column($dados['dominios'], 'is_principal'));
        $this->assertSame(['Subdomínio'], array_values(array_unique(array_column($dados['dominios'], 'tipo_descricao'))));

        $auditoria = $resposta->json('props.auditoria');
        $this->assertCount(1, $auditoria, 'Só a auditoria desta escola.');
        $this->assertSame('escola.criada', $auditoria[0]['accao']);
        $this->assertSame('Rui Operador', $auditoria[0]['autor']);
        $this->assertSame(['dominio' => 'alfa.mositec.test'], $auditoria[0]['detalhe']);
    }

    public function test_o_detalhe_mostra_os_tres_estados_com_motivo_e_datas(): void
    {
        $this->escola('MOSI-000101', 'Alfa', 'alfa.mositec.test');
        $this->escola('MOSI-000102', 'Beta', 'beta.mositec.test', EstadoTenant::SUSPENSO, [
            'motivo_suspensao' => 'Falta de pagamento',
            'suspenso_em' => '2026-09-01 10:00:00',
        ]);
        $this->escola('MOSI-000103', 'Gama', 'gama.mositec.test', EstadoTenant::ENCERRADO, [
            'encerrado_em' => '2026-09-15 08:30:00',
            'suspenso_em' => '2026-09-01 10:00:00',
            'motivo_suspensao' => 'Contrato terminado',
        ]);

        $activa = $this->inertiaGet('/plataforma/escolas/MOSI-000101')->assertOk()->json('props.escola');
        $suspensa = $this->inertiaGet('/plataforma/escolas/MOSI-000102')->assertOk()->json('props.escola');
        // Escolas encerradas continuam visíveis no painel.
        $encerrada = $this->inertiaGet('/plataforma/escolas/MOSI-000103')->assertOk()->json('props.escola');

        $this->assertSame(1, $activa['estado']);
        $this->assertNull($activa['suspenso_em']);
        $this->assertSame(2, $suspensa['estado']);
        $this->assertSame('Falta de pagamento', $suspensa['motivo_suspensao']);
        $this->assertStringContainsString('2026-09-01', $suspensa['suspenso_em']);
        $this->assertNull($suspensa['encerrado_em']);
        $this->assertSame(3, $encerrada['estado']);
        $this->assertStringContainsString('2026-09-15', $encerrada['encerrado_em']);
        $this->assertSame('Contrato terminado', $encerrada['motivo_suspensao']);
    }

    public function test_o_detalhe_mostra_so_as_20_acoes_mais_recentes_da_escola(): void
    {
        $this->escola('MOSI-000101', 'Alfa', 'alfa.mositec.test');
        for ($i = 1; $i <= 25; $i++) {
            $registo = RegistoDeAuditoria::create(['accao' => "teste.accao-{$i}", 'codigo_tenant' => 'MOSI-000101']);
            $registo->forceFill(['created_at' => now()->subMinutes(100 - $i)])->save();
        }

        $auditoria = $this->inertiaGet('/plataforma/escolas/MOSI-000101')->json('props.auditoria');

        $this->assertCount(20, $auditoria);
        $this->assertSame('teste.accao-25', $auditoria[0]['accao'], 'Mais recente primeiro.');
        $this->assertSame('teste.accao-6', $auditoria[19]['accao']);
    }

    public function test_codigo_inexistente_da_404_e_nova_nao_e_interpretado_como_codigo(): void
    {
        $this->noPainel('GET', '/plataforma/escolas/MOSI-999999', $this->sessao)->assertNotFound();

        // Controlo: `nova` é a página do formulário.
        $nova = $this->inertiaGet('/plataforma/escolas/nova');
        $nova->assertOk();
        $this->assertSame('Plataforma/Escolas/Nova', $nova->json('component'));

        // Mesmo que exista um tenant cujo código seja `nova`, a rota literal ganha.
        Tenant::create(['codigo' => 'nova', 'nome' => 'Escola Nova']);
        $this->assertSame('Plataforma/Escolas/Nova', $this->inertiaGet('/plataforma/escolas/nova')->json('component'));
    }

    public function test_o_codigo_resolve_se_pelo_codigo_e_nunca_pelo_id(): void
    {
        $escola = $this->escola('MOSI-000101', 'Alfa', 'alfa.mositec.test');

        $this->inertiaGet('/plataforma/escolas/' . $escola->id)->assertNotFound();
        $this->inertiaGet('/plataforma/escolas/MOSI-000101')->assertOk();
    }

    public function test_a_resolucao_por_codigo_so_vale_no_grupo_da_plataforma(): void
    {
        // Uma rota da escola com um parâmetro `{tenant}` recebe o texto cru: a bind não a afecta
        // (se a afectasse, o closure receberia um Tenant e falharia o tipo `string`).
        Route::middleware('web')->get('/_outra/{tenant}', fn (string $tenant) => 'valor:' . $tenant);
        $this->criarTenant('MOSI-000900', 'Escola do Host', self::ESCOLA);
        $this->escola('MOSI-000101', 'Alfa', 'alfa.mositec.test');

        $this->pedido('GET', 'http://' . self::ESCOLA . '/_outra/MOSI-000101')
            ->assertOk()
            ->assertSee('valor:MOSI-000101');
    }

    // --- Início ------------------------------------------------------------------------------

    public function test_o_inicio_redirecciona_para_a_listagem_de_escolas(): void
    {
        $this->noPainel('GET', '/plataforma', $this->sessao)
            ->assertRedirect(route('plataforma.escolas.index'));
        $this->assertSame('/plataforma/escolas', route('plataforma.escolas.index', absolute: false));
    }

    // --- Criação -----------------------------------------------------------------------------

    public function test_criar_escola_chama_a_action_com_o_dto_e_redirecciona_para_o_detalhe(): void
    {
        $capturado = null;
        $existente = $this->escola('MOSI-000555', 'Escola Simulada', 'simulada.mositec.test');
        $this->mock(CriarTenantAction::class, function (MockInterface $mock) use (&$capturado, $existente) {
            $mock->shouldReceive('executar')->once()->andReturnUsing(function (CriarTenantDTO $dto) use (&$capturado, $existente) {
                $capturado = $dto;

                return new TenantCriado($existente, $existente->dominios()->first(), new CredencialInicial('maria@escola-nova.test', 'Senh@-Simulada-9!'));
            });
        });

        $resposta = $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario(['codigo' => ' MOSI-000555 ']));

        $resposta->assertRedirect(route('plataforma.escolas.show', 'MOSI-000555'));
        $this->assertInstanceOf(CriarTenantDTO::class, $capturado);
        $this->assertSame('Escola Nova do Lubango', $capturado->nomeEstabelecimento);
        $this->assertSame('Maria Directora', $capturado->nomeAdministrador);
        $this->assertSame('maria@escola-nova.test', $capturado->emailAdministrador);
        $this->assertSame('lubango.mositec.test', $capturado->dominioPrincipal);
        $this->assertSame('MOSI-000555', $capturado->codigo);
    }

    public function test_o_codigo_em_branco_chega_a_action_como_nulo(): void
    {
        $capturado = null;
        $existente = $this->escola('MOSI-000555', 'Escola Simulada', 'simulada.mositec.test');
        $this->mock(CriarTenantAction::class, function (MockInterface $mock) use (&$capturado, $existente) {
            $mock->shouldReceive('executar')->once()->andReturnUsing(function (CriarTenantDTO $dto) use (&$capturado, $existente) {
                $capturado = $dto;

                return new TenantCriado($existente, $existente->dominios()->first(), new CredencialInicial('a@b.test', 'x'));
            });
        });

        $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario(['codigo' => '   ']))->assertRedirect();

        $this->assertNull($capturado->codigo);
    }

    public function test_as_regras_de_dominio_vem_da_action_e_nao_do_controller(): void
    {
        // (a) A Action recusa com a SUA mensagem: aparece no campo, tal e qual.
        $this->mock(CriarTenantAction::class, function (MockInterface $mock) {
            $mock->shouldReceive('executar')->once()->andThrow(new DadosDeTenantInvalidos(['dominio' => 'MENSAGEM-SENTINELA-DA-ACTION']));
        });
        $antes = Tenant::count();

        $resposta = $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario());

        $resposta->assertRedirect(route('plataforma.escolas.nova'));
        $resposta->assertSessionHasErrors(['dominio' => 'MENSAGEM-SENTINELA-DA-ACTION']);
        $this->assertSame($antes, Tenant::count());
    }

    public function test_o_controller_nao_copia_a_regra_de_dominio(): void
    {
        // (b) Um domínio que a regra copiada recusaria (reservado, host central, duplicado) mas que a Action
        // (aqui simulada) aceita, passa: o controller não decide nada sobre domínios.
        $existente = $this->escola('MOSI-000555', 'Escola Simulada', 'simulada.mositec.test');
        $this->mock(CriarTenantAction::class, function (MockInterface $mock) use ($existente) {
            $mock->shouldReceive('executar')->times(3)->andReturn(new TenantCriado($existente, $existente->dominios()->first(), new CredencialInicial('a@b.test', 'x')));
        });

        foreach (['admin.mositec.test', self::CENTRAL, 'simulada.mositec.test'] as $dominio) {
            $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario(['dominio' => $dominio]))
                ->assertRedirect(route('plataforma.escolas.show', 'MOSI-000555'));
        }
    }

    public function test_o_formulario_so_valida_o_formato_e_nao_chama_a_action(): void
    {
        $this->mock(CriarTenantAction::class, fn (MockInterface $mock) => $mock->shouldNotReceive('executar'));

        $this->noPainel('POST', '/plataforma/escolas', $this->sessao, ['nome' => '', 'admin_nome' => '', 'admin_email' => '', 'dominio' => ''])
            ->assertSessionHasErrors(['nome', 'admin_nome', 'admin_email', 'dominio']);
        $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario(['nome' => ['x'], 'dominio' => str_repeat('a', 300)]))
            ->assertSessionHasErrors(['nome', 'dominio']);
        $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario(['codigo' => str_repeat('X', 100)]))
            ->assertSessionHasErrors(['codigo']);
    }

    public function test_criar_escola_a_serio_cria_tudo_e_leva_ao_detalhe(): void
    {
        $antes = $this->contagens();

        $resposta = $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario());

        $escola = Tenant::query()->where('nome', 'Escola Nova do Lubango')->sole();
        $resposta->assertRedirect(route('plataforma.escolas.show', $escola->codigo));
        $this->assertMatchesRegularExpression('/^MOSI-\d{6}$/', $escola->codigo);
        $this->assertSame(EstadoTenant::ACTIVO, $escola->estado);
        $principal = $escola->dominios()->sole();
        $this->assertSame('lubango.mositec.test', $principal->dominio);
        $this->assertTrue($principal->is_principal);
        $depois = $this->contagens();
        $this->assertSame($antes['tenants'] + 1, $depois['tenants']);
        $this->assertSame($antes['users'] + 1, $depois['users'], 'O administrador inicial foi criado pela Action.');
    }

    public function test_aceita_o_codigo_opcional_e_a_action_usa_o(): void
    {
        $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario(['codigo' => 'MOSI-004242']))
            ->assertRedirect(route('plataforma.escolas.show', 'MOSI-004242'));

        $this->assertTrue(Tenant::where('codigo', 'MOSI-004242')->exists());
    }

    public function test_a_senha_temporaria_aparece_uma_so_vez_e_nunca_em_claro_no_resto(): void
    {
        $logs = [];
        Log::listen(function ($mensagem) use (&$logs) {
            $logs[] = $mensagem->message . ' ' . json_encode($mensagem->context);
        });

        $criar = $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario());
        $escola = Tenant::query()->where('nome', 'Escola Nova do Lubango')->sole();
        $criar->assertRedirect(route('plataforma.escolas.show', $escola->codigo));

        // O payload bruto da sessão (driver `database`) guarda o flash, mas cifrado.
        $payloadAposCriar = $this->payloadsDasSessoes();
        $this->assertStringContainsString('senha_temporaria', $payloadAposCriar);

        // Primeira visita: a senha vem uma vez, com código e e-mail, e a resposta cifra o histórico.
        $primeira = $this->inertiaGet('/plataforma/escolas/' . $escola->codigo);
        $primeira->assertOk();
        $flash = $primeira->json('props.flash.senha_temporaria');
        $this->assertSame($escola->codigo, $flash['codigo']);
        $this->assertSame('maria@escola-nova.test', $flash['email']);
        $senha = $flash['senha'];
        $this->assertGreaterThanOrEqual(12, strlen($senha));
        $this->assertTrue($primeira->json('encryptHistory'));

        // Em lado nenhum mais das props.
        $semFlash = $primeira->json('props');
        unset($semFlash['flash']['senha_temporaria']);
        $this->assertStringNotContainsString($senha, json_encode($semFlash));

        // O payload gravado depois de criar nunca teve a senha em claro.
        $this->assertStringNotContainsString($senha, $payloadAposCriar);

        // Segunda visita: sem flash, sem cifra do histórico, sem senha em lado nenhum.
        $segunda = $this->inertiaGet('/plataforma/escolas/' . $escola->codigo);
        $this->assertNull($segunda->json('props.flash.senha_temporaria'));
        $this->assertFalse((bool) $segunda->json('encryptHistory'));
        $this->assertStringNotContainsString($senha, $segunda->getContent());
        $this->assertStringNotContainsString($senha, $this->inertiaGet('/plataforma/escolas')->getContent());
        $this->assertStringNotContainsString($senha, $this->payloadsDasSessoes());

        // Nunca em logs nem na auditoria.
        $this->assertStringNotContainsString($senha, implode("\n", $logs));
        $this->assertStringNotContainsString($senha, json_encode(RegistoDeAuditoria::all()->toArray()));
        $this->assertStringNotContainsString($senha, json_encode(DB::table('plataforma_auditoria')->get()->all()));

        // A senha serve mesmo para o administrador criado (e só existe o hash na BD).
        $hash = $this->noTenant($escola, fn () => DB::table('users')->where('email', 'maria@escola-nova.test')->value('password'));
        $this->assertNotSame($senha, $hash);
        $this->assertTrue(password_verify($senha, $hash));
    }

    private function payloadsDasSessoes(): string
    {
        return DB::table('sessions')->pluck('payload')->map(fn ($p) => base64_decode($p) . $p)->implode("\n");
    }

    public function test_a_criacao_regista_auditoria_sem_senha(): void
    {
        $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario());

        $escola = Tenant::query()->where('nome', 'Escola Nova do Lubango')->sole();
        $registo = RegistoDeAuditoria::query()->where('accao', 'escola.criada')->sole();
        $this->assertSame($this->admin->id, $registo->super_admin_id);
        $this->assertSame($escola->codigo, $registo->codigo_tenant);
        $this->assertSame('lubango.mositec.test', $registo->detalhe['dominio']);
        $this->assertSame('10.0.0.1', $registo->ip);
        $this->assertStringNotContainsString('senha', mb_strtolower(json_encode($registo->detalhe)));
        $this->assertStringNotContainsString('password', mb_strtolower(json_encode($registo->detalhe)));
    }

    public static function erros_da_action(): array
    {
        return [
            'domínio duplicado' => [['dominio' => 'ocupado.mositec.test'], 'dominio'],
            'domínio reservado' => [['dominio' => 'admin.mositec.test'], 'dominio'],
            'domínio igual a host central' => [['dominio' => self::CENTRAL], 'dominio'],
            'domínio de formato inválido' => [['dominio' => 'não é um domínio!'], 'dominio'],
            'e-mail inválido' => [['admin_email' => 'isto-nao-e-email'], 'admin_email'],
            'código duplicado' => [['codigo' => 'MOSI-000777'], 'codigo'],
            'código de formato inválido' => [['codigo' => 'ABC-1'], 'codigo'],
        ];
    }

    #[DataProvider('erros_da_action')]
    public function test_erros_da_action_aparecem_nos_campos_e_nada_e_criado(array $sobrepor, string $campo): void
    {
        $this->escola('MOSI-000777', 'Escola Ocupada', 'ocupado.mositec.test');
        $contagensAntes = $this->contagens();
        $auditoriaAntes = RegistoDeAuditoria::count();

        $resposta = $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario($sobrepor));

        $resposta->assertRedirect(route('plataforma.escolas.nova'));
        $resposta->assertSessionHasErrors([$campo]);
        $this->assertSame($contagensAntes, $this->contagens(), 'Atomicidade: nada foi criado (Tenant, Domain, Estabelecimento, roles, User).');
        $this->assertSame($auditoriaAntes, RegistoDeAuditoria::count(), 'Sem auditoria de sucesso.');
        // O input volta ao formulário (nada sensível existe neste formulário).
        $this->assertSame('Escola Nova do Lubango', session()->getOldInput('nome'));
        $this->assertSame('Maria Directora', session()->getOldInput('admin_nome'));
    }

    public function test_todos_os_erros_da_action_sao_mostrados_de_uma_vez(): void
    {
        $this->escola('MOSI-000777', 'Escola Ocupada', 'ocupado.mositec.test');

        $resposta = $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario(['dominio' => 'ocupado.mositec.test', 'admin_email' => 'mau', 'codigo' => 'MOSI-000777']));

        $resposta->assertSessionHasErrors(['dominio', 'admin_email', 'codigo']);
    }

    public function test_os_erros_chegam_ao_formulario_como_props_do_inertia(): void
    {
        $this->escola('MOSI-000777', 'Escola Ocupada', 'ocupado.mositec.test');
        $criar = $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario(['dominio' => 'ocupado.mositec.test']), $this->cabecalhosInertia());
        $criar->assertRedirect(route('plataforma.escolas.nova'));

        $nova = $this->inertiaGet('/plataforma/escolas/nova');

        $this->assertStringContainsString('já está registado', $nova->json('props.errors.dominio'));
    }

    public function test_os_erros_sem_campo_proprio_tambem_chegam_ao_formulario(): void
    {
        $this->mock(CriarTenantAction::class, function (MockInterface $mock) {
            $mock->shouldReceive('executar')->once()->andThrow(new DadosDeTenantInvalidos(['conflito' => 'Registado por outro pedido em simultâneo.']));
        });

        $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $this->formulario())
            ->assertSessionHasErrors(['conflito' => 'Registado por outro pedido em simultâneo.']);
    }

    // --- Acesso e contexto -------------------------------------------------------------------

    public static function rotas_das_escolas(): array
    {
        return [
            'listagem' => ['GET', '/plataforma/escolas'],
            'detalhe' => ['GET', '/plataforma/escolas/MOSI-000001'],
            'nova' => ['GET', '/plataforma/escolas/nova'],
            'detalhe inexistente' => ['GET', '/plataforma/escolas/MOSI-999999'],
            'criar' => ['POST', '/plataforma/escolas'],
        ];
    }

    #[DataProvider('rotas_das_escolas')]
    public function test_sem_autenticacao_vai_para_o_login_e_nada_e_criado(string $metodo, string $caminho): void
    {
        $antes = $this->contagens();

        $this->pedido($metodo, $this->urlCentral($caminho), $this->formulario())
            ->assertRedirect($this->urlCentral('/plataforma/login'));

        $this->assertSame($antes, $this->contagens());
    }

    #[DataProvider('rotas_das_escolas')]
    public function test_conta_desactivada_vai_para_o_login_e_nada_e_criado(string $metodo, string $caminho): void
    {
        $this->admin->update(['estado' => 0]);
        $antes = $this->contagens();

        $this->noPainel($metodo, $caminho, $this->sessao, $this->formulario())
            ->assertRedirect($this->urlCentral('/plataforma/login'));

        $this->assertSame($antes, $this->contagens());
    }

    #[DataProvider('rotas_das_escolas')]
    public function test_as_rotas_dao_404_fora_do_host_central(string $metodo, string $caminho): void
    {
        $this->criarTenant('MOSI-000900', 'Escola do Host', self::ESCOLA);
        $antes = $this->contagens();

        $this->pedido($metodo, 'http://' . self::ESCOLA . $caminho, $this->formulario())->assertNotFound();

        $this->assertSame($antes, $this->contagens());
    }

    public function test_o_contexto_de_tenant_continua_vazio_depois_de_cada_pedido_inclusive_o_store(): void
    {
        $escola = $this->escola('MOSI-000101', 'Alfa', 'alfa.mositec.test');
        $contexto = app(TenantContext::class);

        foreach ([['GET', '/plataforma/escolas'], ['GET', '/plataforma/escolas/' . $escola->codigo], ['GET', '/plataforma/escolas/nova'], ['POST', '/plataforma/escolas'], ['GET', '/plataforma/escolas/MOSI-999999']] as [$metodo, $caminho]) {
            $this->noPainel($metodo, $caminho, $this->sessao, $this->formulario());
            $this->assertFalse($contexto->temTenant(), "Contexto aberto depois de {$metodo} {$caminho}");
        }
        $this->assertTrue(Tenant::where('nome', 'Escola Nova do Lubango')->exists(), 'O store correu a sério.');
    }

    public function test_a_action_restaura_o_contexto_anterior(): void
    {
        $contexto = app(TenantContext::class);
        $anterior = $this->escola('MOSI-000101', 'Alfa', 'alfa.mositec.test');
        $contexto->definir($anterior->paraTenantAtual());

        app(CriarTenantAction::class)->executar(new CriarTenantDTO('Outra', 'Ana', 'ana@outra.test', 'outra.mositec.test'));

        $this->assertSame($anterior->id, $contexto->atual()->id, 'O contexto de antes do pedido é restaurado pela Action.');
    }

    // --- Arquitectura ------------------------------------------------------------------------

    public function test_o_controller_e_fino_e_nao_conhece_regras_nem_models_de_escola(): void
    {
        $codigo = (string) file_get_contents(base_path('Modules/Plataforma/app/Http/Controllers/EscolaController.php'));

        foreach (['ValidadorDominio', 'Domain::', 'executarComo', 'TenantContext', 'DB::', 'withoutGlobalScopes', 'FILTER_VALIDATE_EMAIL', 'hosts_centrais', 'HostsCentrais', 'GeradorCodigoTenant'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $codigo, "O EscolaController não pode usar {$proibido}.");
        }
        $this->assertStringContainsString('CriarTenantAction', $codigo);
        $this->assertStringContainsString('Crypt::encryptString', $codigo);
    }
}
