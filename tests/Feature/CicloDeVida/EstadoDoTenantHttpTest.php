<?php

namespace Tests\Feature\CicloDeVida;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Tenant\Actions\EncerrarTenantAction;
use Modules\Tenant\Actions\ReactivarTenantAction;
use Modules\Tenant\Actions\SuspenderTenantAction;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EstadoDoTenantHttpTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'Dívida de licença MOTIVO-SECRETO';

    private function suspender(?Tenant $tenant = null): void
    {
        app(SuspenderTenantAction::class)->executar($tenant ?? $this->tenant, self::MOTIVO);
    }

    private function encerrar(?Tenant $tenant = null): void
    {
        app(EncerrarTenantAction::class)->executar($tenant ?? $this->tenant);
    }

    private function utilizador(string $email = 'a@example.com'): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
    }

    /** Caminhos web que nenhum estado não-activo pode deixar passar (nenhum depende de existir o recurso). */
    public static function caminhosWeb(): array
    {
        return [
            'raiz' => ['GET', '/'],
            'login' => ['GET', '/login'],
            'login (post)' => ['POST', '/login'],
            'logout' => ['POST', '/logout'],
            'foto de aluno' => ['GET', '/alunos/1/foto'],
            'download de documento' => ['GET', '/documentos-pessoa/1/download'],
            'inexistente' => ['GET', '/qualquer/coisa'],
        ];
    }

    // ---- Suspenso ---------------------------------------------------------

    public function test_suspenso_mostra_a_pagina_de_conta_suspensa_com_403(): void
    {
        $this->suspender();

        $resposta = $this->get('/login')
            ->assertForbidden()
            ->assertSee('Conta suspensa')
            ->assertSee('<html', false);

        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }

    public function test_a_pagina_nao_expoe_o_motivo_nem_dados_do_tenant(): void
    {
        $this->suspender();

        $resposta = $this->get('/login')->assertForbidden();

        $resposta->assertDontSee('MOTIVO-SECRETO');
        $resposta->assertDontSee('Dívida');
        $resposta->assertDontSee('Escola de Teste');
        $resposta->assertDontSee('MOSI-000001');
        $resposta->assertDontSee('<form', false);
    }

    #[DataProvider('caminhosWeb')]
    public function test_suspenso_bloqueia_todos_os_caminhos_web(string $metodo, string $caminho): void
    {
        $this->suspender();

        $this->call($metodo, $caminho)->assertForbidden()->assertSee('Conta suspensa');
    }

    public function test_suspenso_nao_abre_contexto_de_tenant(): void
    {
        $this->suspender();
        app(TenantContext::class)->limpar();
        $viuTenant = null;
        Route::get('/_ciclo/contexto', function () use (&$viuTenant) {
            $viuTenant = app(TenantContext::class)->temTenant();

            return 'ok';
        });

        $this->get('/_ciclo/contexto')->assertForbidden();

        $this->assertNull($viuTenant, 'A rota nem devia ter corrido.');
        $this->assertFalse(app(TenantContext::class)->temTenant());
    }

    public function test_suspenso_api_responde_403_json_sem_expor_dados(): void
    {
        $this->suspender();

        $this->getJson('/api/v1/turmas')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Conta suspensa.']);

        $this->postJson('/api/v1/autenticacaoApi/api/login', ['email' => 'a@example.com', 'password' => 'x'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Conta suspensa.']);
    }

    public function test_suspenso_pedido_que_espera_json_fora_de_api_tambem_recebe_json(): void
    {
        $this->suspender();

        $this->getJson('/qualquer/coisa')->assertForbidden()->assertExactJson(['message' => 'Conta suspensa.']);
    }

    public function test_suspenso_pedido_inertia_forca_recarga_completa_para_mostrar_a_pagina(): void
    {
        $this->suspender();

        $this->get('/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'x'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'http://localhost/login');
    }

    public function test_suspenso_recusa_o_login(): void
    {
        $this->utilizador();
        $this->suspender();

        $this->post('/login', ['login' => 'a@example.com', 'password' => 'segredo123'])->assertForbidden();

        $this->assertGuest();
    }

    public function test_sessao_existente_deixa_de_valer_no_pedido_seguinte_e_volta_ao_reactivar(): void
    {
        $this->utilizador();
        $this->post('/login', ['login' => 'a@example.com', 'password' => 'segredo123']);
        $this->assertAuthenticated();
        $this->get('/')->assertOk();

        $this->suspender();
        $this->get('/')->assertForbidden()->assertSee('Conta suspensa');

        app(ReactivarTenantAction::class)->executar($this->tenant);
        $this->get('/')->assertOk();
    }

    public function test_token_sanctum_e_rejeitado_em_suspenso_e_volta_a_valer_ao_reactivar(): void
    {
        $plain = $this->utilizador()->createToken('api-token')->plainTextToken;
        $url = '/api/v1/autenticacaoApi/api/logout-all-devices';

        $this->suspender();
        $this->withToken($plain)->postJson($url)->assertForbidden()->assertExactJson(['message' => 'Conta suspensa.']);

        app(ReactivarTenantAction::class)->executar($this->tenant);
        $this->withToken($plain)->postJson($url)->assertSuccessful();
    }

    public function test_reactivar_volta_ao_normal_com_os_dados_intactos(): void
    {
        $this->estabelecimentoDeTeste(['nome' => 'Colégio Intacto']);
        $this->utilizador();

        $this->suspender();
        $this->get('/login')->assertForbidden();
        app(ReactivarTenantAction::class)->executar($this->tenant);

        $this->get('/login')->assertOk();
        $this->assertSame('Colégio Intacto', Estabelecimento::current()->nome);
        $this->assertSame(1, User::query()->count());
    }

    public function test_suspender_um_tenant_nao_afecta_o_outro(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->suspender();

        $this->get($this->urlDoTenant($this->tenant, '/login'))->assertForbidden();
        $this->get($this->urlDoTenant($b, '/login'))->assertOk();
        $this->getJson($this->urlDoTenant($b, '/api/v1/turmas'))->assertUnauthorized();
    }

    public function test_dominio_central_e_verificacao_de_saude_continuam_a_funcionar_com_tenant_suspenso(): void
    {
        config(['tenancy.hosts_centrais' => ['plataforma.localhost']]);
        Route::get('/_ciclo/central', fn () => 'central-ok');
        $this->suspender();

        $this->get('http://plataforma.localhost/_ciclo/central')->assertOk()->assertSee('central-ok');
        // /up responde em qualquer host por desenho (§5.2): não expõe dados de tenant.
        $this->get('http://localhost/up')->assertOk();
    }

    public function test_modo_unico_suspenso_tambem_bloqueia_qualquer_host(): void
    {
        config(['tenancy.modo' => 'unico']);
        $this->suspender();

        $this->get('http://192.168.1.10/login')->assertForbidden()->assertSee('Conta suspensa');
        $this->getJson('http://192.168.1.10/api/v1/turmas')->assertForbidden();
    }

    // ---- Encerrado --------------------------------------------------------

    #[DataProvider('caminhosWeb')]
    public function test_encerrado_responde_404_em_todos_os_caminhos_web(string $metodo, string $caminho): void
    {
        $this->encerrar();

        $this->call($metodo, $caminho)->assertNotFound();
    }

    public function test_encerrado_e_indistinguivel_de_dominio_desconhecido(): void
    {
        config(['app.debug' => false]);
        $this->encerrar();

        $encerrado = $this->get('/login');
        $desconhecido = $this->get('http://nao-existe.localhost/login');

        $encerrado->assertNotFound();
        $desconhecido->assertNotFound();
        $this->assertSame($desconhecido->getContent(), $encerrado->getContent());
        $this->assertStringNotContainsString('Conta', $encerrado->getContent());

        $apiEncerrado = $this->getJson('/api/v1/turmas');
        $apiDesconhecida = $this->getJson('http://nao-existe.localhost/api/v1/turmas');
        $apiEncerrado->assertNotFound();
        $this->assertSame($apiDesconhecida->getContent(), $apiEncerrado->getContent());
    }

    public function test_encerrado_suspenso_antes_continua_a_dar_404_e_nao_a_pagina_de_suspensao(): void
    {
        $this->suspender();
        $this->encerrar();

        $this->get('/login')->assertNotFound()->assertDontSee('Conta suspensa');
    }

    public function test_encerrado_recusa_login_e_token(): void
    {
        $this->utilizador();
        $plain = $this->utilizador('b@example.com')->createToken('t')->plainTextToken;
        $this->encerrar();

        $this->post('/login', ['login' => 'a@example.com', 'password' => 'segredo123'])->assertNotFound();
        $this->assertGuest();
        $this->withToken($plain)->postJson('/api/v1/autenticacaoApi/api/logout')->assertNotFound();
    }

    public function test_encerrado_nao_abre_contexto_nem_mexe_nos_dados(): void
    {
        $this->encerrar();
        app(TenantContext::class)->limpar();

        $this->get('/login')->assertNotFound();

        $this->assertFalse(app(TenantContext::class)->temTenant());
        $this->assertSame(EstadoTenant::ENCERRADO, Tenant::findOrFail($this->tenant->id)->estado);
        $this->assertSame(1, Estabelecimento::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_encerrar_um_tenant_nao_afecta_o_outro(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->encerrar();

        $this->get($this->urlDoTenant($b, '/login'))->assertOk();
    }

    public function test_modo_unico_encerrado_da_404(): void
    {
        config(['tenancy.modo' => 'unico']);
        $this->encerrar();

        $this->get('http://192.168.1.10/login')->assertNotFound();
    }

    // ---- Tenant mínimo ----------------------------------------------------

    public function test_estado_do_tenant_atual_reflecte_o_activo_inactivo(): void
    {
        $this->assertTrue($this->tenant->estaActivo());
        $this->suspender();
        $this->assertFalse($this->tenant->fresh()->estaActivo());
        $this->assertTrue($this->tenant->fresh()->estaSuspenso());
        $this->encerrar();
        $this->assertTrue($this->tenant->fresh()->estaEncerrado());
    }

    // ---- Vaga de limpeza --------------------------------------------------

    public function test_respostas_json_e_inertia_de_suspenso_tambem_sao_no_store(): void
    {
        $this->suspender();

        foreach ([
            $this->getJson('/api/v1/turmas'),
            $this->get('/login', ['X-Inertia' => 'true']),
        ] as $resposta) {
            $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        }
    }

    public function test_suspenso_bloqueia_tambem_o_dominio_secundario(): void
    {
        $this->tenant->dominios()->create(['dominio' => 'secundario.localhost']);
        $this->suspender();

        $this->get('http://secundario.localhost/login')->assertForbidden()->assertSee('Conta suspensa');
        $this->getJson('http://secundario.localhost/api/v1/turmas')->assertForbidden()->assertExactJson(['message' => 'Conta suspensa.']);
    }

    public function test_404_de_encerrado_e_de_desconhecido_tem_os_mesmos_headers_e_corpo(): void
    {
        config(['app.debug' => false]);
        $this->encerrar();

        $relevantes = function ($r) {
            $h = collect($r->headers->all())->except(['date', 'set-cookie'])->map(fn ($v) => implode(',', $v))->sortKeys()->all();
            // Cache-Control pode trazer hash/ordem estáveis; mantém-se na comparação.
            return $h;
        };

        foreach ([
            [fn ($h) => $this->get("http://{$h}/login"), 'web'],
            [fn ($h) => $this->getJson("http://{$h}/api/v1/turmas"), 'json'],
        ] as [$pedido, $tipo]) {
            $a = $pedido('localhost');
            $b = $pedido('nao-existe.localhost');
            $a->assertNotFound();
            $b->assertNotFound();
            $this->assertSame($relevantes($b), $relevantes($a), "headers ({$tipo})");
            $this->assertSame($b->getContent(), $a->getContent(), "corpo ({$tipo})");
        }
    }
}
