<?php

namespace Modules\Licenciamento\Tests\Feature;

use App\Services\DashboardService;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Licenciamento\Contracts\ModulosDisponiveis;
use Modules\Core\Licenciamento\Contracts\TodosOsModulos;
use Modules\Licenciamento\Services\DireitosService;
use Modules\Licenciamento\Services\ModulosContratados;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ModulosContratadosTest extends TestCase
{
    use ComLicencaDeTeste;
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararLicencas();
        $this->congelarRelogioDeTeste();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function admin(): User
    {
        $user = User::firstOrCreate(['email' => 'adm@example.com'], ['name' => 'Admin', 'password' => Hash::make('x')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        return $user;
    }

    private function licenca(array $modulos): void
    {
        app(InstalarLicencaAction::class)->executar($this->tenant->id, $this->tenant->codigo, $this->tokenDeTeste(['mod' => $modulos, 'jti' => 'lic-'.++$this->sequencia, 'iat' => sprintf('2026-01-%02dT00:00:00+00:00', $this->sequencia), 'nbf' => sprintf('2026-01-%02dT00:00:00+00:00', $this->sequencia)]));
    }

    public function test_modulo_contratado_responde(): void
    {
        $this->licenca(['financeiro', 'avaliacao']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/financeiro/propinas')->assertOk();
        $this->actingAs($admin)->get('/avaliacao/notas')->assertOk();
    }

    public function test_modulo_nao_contratado_da_404_em_web_e_json(): void
    {
        $this->licenca(['avaliacao']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/financeiro/propinas')->assertNotFound();
        $this->actingAs($admin)->getJson('/financeiro/propinas')->assertNotFound();
        $this->actingAs($admin)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])->get('/financeiro/dividas')->assertNotFound();
        $this->actingAs($admin)->post('/financeiro/propinas/geracao', [])->assertNotFound();
        $this->actingAs($admin)->get('/avaliacao/notas')->assertOk();
    }

    public function test_alias_funciona_tambem_no_grupo_api(): void
    {
        $this->licenca(['avaliacao']);
        Route::middleware(['api', 'auth:sanctum', 'licenca.modulo:financeiro'])->get('/api/teste-modulo-financeiro', fn () => ['ok' => true]);
        Route::middleware(['api', 'auth:sanctum', 'licenca.modulo:avaliacao'])->get('/api/teste-modulo-avaliacao', fn () => ['ok' => true]);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/teste-modulo-financeiro')->assertNotFound();
        $this->getJson('/api/teste-modulo-avaliacao')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_resposta_404_e_neutra_igual_a_rota_inexistente(): void
    {
        config(['app.debug' => false]);
        $this->licenca(['avaliacao']);
        $admin = $this->admin();

        $negado = $this->actingAs($admin)->getJson('/financeiro/propinas');
        $inexistente = $this->actingAs($admin)->getJson('/financeiro/nao-existe-mesmo');

        $this->assertSame($inexistente->getStatusCode(), $negado->getStatusCode());
        $cabecalhos = strtolower(json_encode($negado->headers->all()).$negado->getContent());
        foreach (['licen', 'contrat', 'modul', 'financeiro'] as $pista) {
            $this->assertStringNotContainsString($pista, $cabecalhos);
        }

        $html = $this->actingAs($admin)->get('/financeiro/propinas');
        $this->assertSame($this->actingAs($admin)->get('/financeiro/nao-existe-mesmo')->getContent(), $html->getContent());
    }

    public function test_visitante_sem_sessao_vai_para_login_nao_para_404_nem_erro(): void
    {
        $this->licenca([]);

        $this->get('/financeiro/propinas')->assertRedirect(route('login'));
    }

    public function test_modulos_base_nunca_sao_bloqueados(): void
    {
        $this->licenca([]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/alunos')->assertOk();
        $this->actingAs($admin)->get('/turmas')->assertOk();
    }

    public function test_licenca_sem_modulos_nega_os_licenciaveis(): void
    {
        $this->licenca([]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/financeiro/propinas')->assertNotFound();
        $this->actingAs($admin)->get('/avaliacao/notas')->assertNotFound();
        $this->actingAs($admin)->get('/minhas-notas')->assertNotFound();
    }

    public function test_sem_licenca_nenhuma_nega_os_licenciaveis(): void
    {
        $this->actingAs($this->admin());

        $this->assertSame([], app(DireitosService::class)->modulosContratados());
        $this->assertFalse(app(DireitosService::class)->moduloDisponivel('financeiro'));
        $this->assertTrue(app(DireitosService::class)->moduloDisponivel('alunos'));
    }

    public function test_menu_nao_mostra_modulo_nao_contratado(): void
    {
        $this->licenca(['avaliacao', 'outro-modulo-nao-licenciavel']);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('modulosContratados', ['avaliacao']));

        $this->licenca(['financeiro', 'avaliacao']);
        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (Assert $p) => $p->where('modulosContratados', ['financeiro', 'avaliacao']));
    }

    public function test_prop_modulos_contratados_nao_revela_datas_nem_limites(): void
    {
        $this->licenca(['financeiro']);
        $professor = User::create(['name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->first()->id);

        $this->actingAs($professor)->get('/')->assertOk()->assertInertia(function (Assert $p) {
            $p->where('modulosContratados', ['financeiro'])->where('licenca', null);
            $json = json_encode($p->toArray()['props']['modulosContratados']);
            $this->assertStringNotContainsString('2027', $json);
            $this->assertStringNotContainsString('500', $json);
        });
    }

    public function test_flag_inactiva_tudo_disponivel(): void
    {
        config(['licenciamento.activo' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/financeiro/propinas')->assertOk();
        $this->actingAs($admin)->get('/avaliacao/notas')->assertOk();
        $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $p) => $p->where('modulosContratados', ['financeiro', 'avaliacao']));
    }

    public function test_modulo_fora_da_lista_licenciavel_nao_e_bloqueado_e_acrescentado_passa_a_ser(): void
    {
        $this->licenca(['avaliacao']);
        Route::middleware(['web', 'auth', 'licenca.modulo:biblioteca'])->get('/teste-biblioteca', fn () => 'ok');
        $admin = $this->admin();

        // Alias que ninguém listou: não bloqueia.
        $this->actingAs($admin)->get('/teste-biblioteca')->assertOk();

        // O dono acrescenta-o à lista (config, sem tocar em código): fica licenciável e a licença não o traz.
        config(['licenciamento.modulos_licenciaveis' => ['financeiro', 'avaliacao', 'biblioteca']]);
        $this->actingAs($admin)->get('/teste-biblioteca')->assertNotFound();
        $this->actingAs($admin)->get('/')->assertInertia(fn (Assert $p) => $p->where('modulosContratados', ['avaliacao']));

        // Com uma licença que o inclui, volta a responder.
        $this->licenca(['avaliacao', 'biblioteca']);
        $this->actingAs($admin)->get('/teste-biblioteca')->assertOk();
    }

    public function test_comando_agendado_do_financeiro_nao_e_afectado(): void
    {
        $this->licenca([]);

        $this->assertSame(0, Artisan::call('financeiro:aplicar-multas', ['--tenant' => $this->tenant->codigo]));
        $this->assertSame(0, Artisan::call('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo]));
    }

    public function test_dashboard_e_pesquisa_escondem_o_financeiro_nao_contratado(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->licenca(['avaliacao']);
        $rotulos = array_column(app(DashboardService::class)->cartoes($admin), 'rotulo');
        $this->assertNotContains('Em dívida', $rotulos);
        $this->assertNotContains('Cobrado este mês', $rotulos);
        $this->assertSame([], app(DashboardService::class)->ultimosPagamentos($admin));

        $this->licenca(['avaliacao', 'financeiro']);
        $rotulos = array_column(app(DashboardService::class)->cartoes($admin), 'rotulo');
        $this->assertContains('Em dívida', $rotulos);
    }

    private function alunoDeTeste(): Aluno
    {
        $pessoa = DadosPessoa::create(['nome_completo' => 'Aluno Um', 'numero_identificacao' => 'X1', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        return Aluno::create(['estabelecimento_id' => $this->estabelecimentoDeTeste()->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => 'T-0001']);
    }

    public function test_paginas_do_aluno_sem_financeiro_nao_trazem_dados_nem_accoes_financeiras(): void
    {
        $aluno = $this->alunoDeTeste();
        $admin = $this->admin();

        $this->licenca(['avaliacao']);
        $this->actingAs($admin)->get('/alunos')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('podeVerPropinas', false)->where('situacoesPropinas', [])->where('moeda', null));
        $this->actingAs($admin)->get("/alunos/{$aluno->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('podeVerPropinas', false)->where('situacaoPropinas', null)->where('podeRegistarPagamento', false)->where('moeda', null));

        // Com o módulo contratado: tudo como antes.
        $this->licenca(['avaliacao', 'financeiro']);
        $this->actingAs($admin)->get("/alunos/{$aluno->id}")->assertInertia(fn (Assert $p) => $p
            ->where('podeVerPropinas', true)->where('podeRegistarPagamento', true));
    }

    public function test_aluno_com_flag_inactiva_fica_como_antes(): void
    {
        config(['licenciamento.activo' => false]);
        $aluno = $this->alunoDeTeste();

        $this->actingAs($this->admin())->get("/alunos/{$aluno->id}")->assertInertia(fn (Assert $p) => $p
            ->where('podeVerPropinas', true)->where('podeRegistarPagamento', true));
    }

    public function test_contrato_do_core_nulo_devolve_true_e_o_do_licenciamento_respeita_regras(): void
    {
        $this->assertTrue((new TodosOsModulos)->disponivel('financeiro'));

        $modulos = app(ModulosDisponiveis::class);
        $this->assertInstanceOf(ModulosContratados::class, $modulos);

        $this->licenca(['avaliacao']);
        $this->assertTrue($modulos->disponivel('avaliacao'));   // contratado
        $this->assertFalse($modulos->disponivel('financeiro')); // licenciável e não contratado
        $this->assertTrue($modulos->disponivel('alunos'));      // base

        config(['licenciamento.activo' => false]);
        $this->assertTrue($modulos->disponivel('financeiro'));  // flag inactiva
    }

    public function test_atalhos_do_dashboard_vem_do_mesmo_filtro_que_a_pagina_usa(): void
    {
        $this->licenca(['avaliacao']);
        $this->actingAs($this->admin())->get('/')->assertInertia(fn (Assert $p) => $p->where('modulosContratados', ['avaliacao']));
    }

    // --- I2: exportações do administrador com a licença bloqueada -----------------------------

    public function test_admin_bloqueado_chega_as_exportacoes_dos_modulos(): void
    {
        // Flag activa e sem licença: BLOQUEADO e nenhum módulo contratado.
        $admin = $this->admin();

        foreach (['/financeiro/dividas/exportar?formato=excel', '/financeiro/relatorios/recebimentos/exportar?formato=excel'] as $url) {
            $this->assertNotSame(404, $this->actingAs($admin)->get($url)->getStatusCode(), $url);
        }
        $this->assertNotSame(404, $this->actingAs($admin)->get('/avaliacao/notas/exportar')->getStatusCode());
    }

    public function test_admin_bloqueado_continua_sem_chegar_ao_resto_do_modulo(): void
    {
        $r = $this->actingAs($this->admin())->get('/financeiro/propinas');

        $this->assertSame(303, $r->getStatusCode()); // o gate leva-o à página da licença
        $this->assertStringEndsWith('/licenca', $r->headers->get('Location'));
    }

    public function test_nao_administrador_bloqueado_nao_chega_a_exportacao(): void
    {
        $prof = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x')]);
        $prof->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->first()->id);

        $this->actingAs($prof)->get('/financeiro/dividas/exportar?formato=excel')->assertStatus(503);
    }

    public function test_sem_bloqueio_modulo_nao_contratado_continua_404_mesmo_para_o_admin_na_exportacao(): void
    {
        $this->licenca(['avaliacao']);

        $this->actingAs($this->admin())->get('/financeiro/dividas/exportar?formato=excel')->assertNotFound();
        $this->actingAs($this->admin())->get('/financeiro/relatorios/recebimentos/exportar?formato=excel')->assertNotFound();
    }

    public function test_pagina_da_licenca_lista_as_exportacoes_com_ligacoes_validas(): void
    {
        $this->actingAs($this->admin())->get('/licenca')->assertOk()->assertInertia(function (Assert $p) {
            $p->where('licenca_estado.modo', 'bloqueado')
                ->where('licenca_estado.ficheiros_url', route('ficheiros.index'))
                ->has('licenca_estado.exportacoes', 5);
            $urls = collect($p->toArray()['props']['licenca_estado']['exportacoes'])->pluck('url');
            $this->assertTrue($urls->contains(route('usuario.exportar', ['formato' => 'excel'])));
            $this->assertTrue($urls->contains(route('financeiro.dividas.exportar', ['formato' => 'excel'])));
        });
    }

    public function test_pagina_da_licenca_esconde_exportacoes_de_modulo_nao_contratado_quando_nao_bloqueado(): void
    {
        $this->licenca(['avaliacao']);

        $this->actingAs($this->admin())->get('/licenca')->assertInertia(function (Assert $p) {
            $nomes = collect($p->toArray()['props']['licenca_estado']['exportacoes'])->pluck('nome');
            $this->assertTrue($nomes->contains('Utilizadores'));
            $this->assertFalse($nomes->contains('Dívidas'));
        });
    }
}
