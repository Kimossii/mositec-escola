<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Tenant\Actions\CriarTenantAction;
use Modules\Tenant\DTO\CriarTenantDTO;
use Modules\Tenant\Enums\TipoDominio;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

/**
 * Criar uma escola nos dois modos de instalação. No modo `unico` (um só tenant) o domínio não se pede: fica registado o host
 * de APP_URL, sem passar pelo ValidadorDominio. No modo `dominio` o domínio é obrigatório e todas as validações continuam
 * (incluindo a recusa do host da própria aplicação, que só se aplica fora dos ambientes local e de testes).
 */
class CriarEscolaModosTest extends TestCase
{
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private const HOST_DA_APP = 'escola-app.exemplo.test';

    private SuperAdmin $admin;

    private string $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        $this->removerOTenantDeTeste();
        config(['tenancy.hosts_centrais' => [self::CENTRAL], 'session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->admin = $this->superAdmin();
        $this->sessao = $this->entrarNoPainel($this->admin);
    }

    /** A base arranca com o tenant de teste por omissão: estes testes contam as escolas e o modo único exige zero para criar a primeira. */
    private function removerOTenantDeTeste(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('estabelecimentos')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('domains')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('tenants')->where('id', $this->tenant->id)->delete();
        Schema::enableForeignKeyConstraints();
    }

    private function comHostDaApp(): void
    {
        config(['app.url' => 'https://'.self::HOST_DA_APP]);
    }

    /**
     * Fora de local/testing o ValidadorDominio recusa o host da aplicação (como em produção). Só se usa nos testes da Action:
     * o painel em HTTP comporta-se de outra forma em produção (cookies seguros).
     */
    private function comoEmProducao(): void
    {
        $this->comHostDaApp();
        $this->app['env'] = 'production';
    }

    /** @return array<string, string|null> */
    private function formulario(array $sobrepor = []): array
    {
        return [
            'nome' => 'Escola Nova',
            'admin_nome' => 'Maria Directora',
            'admin_email' => 'maria@escola-nova.test',
            'dominio' => 'lubango.mositec.test',
            'codigo' => '',
            ...$sobrepor,
        ];
    }

    private function criar(array $formulario)
    {
        return $this->noPainel('POST', '/plataforma/escolas', $this->sessao, $formulario);
    }

    // --- Modo único ---------------------------------------------------------------------------

    public function test_unico_cria_a_escola_sem_dominio_com_o_host_do_app_url_como_referencia(): void
    {
        config(['tenancy.modo' => 'unico']);
        $this->comHostDaApp();

        $resposta = $this->criar($this->formulario(['dominio' => null]));

        $resposta->assertSessionHasNoErrors();
        $this->assertSame(1, Tenant::query()->count());
        $tenant = Tenant::query()->firstOrFail();
        $resposta->assertRedirect(route('plataforma.escolas.show', $tenant->codigo));

        // O registo principal continua a existir, com o host da aplicação.
        $dominio = Domain::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(self::HOST_DA_APP, $dominio->dominio);
        $this->assertTrue((bool) $dominio->is_principal);
        $this->assertSame(TipoDominio::PERSONALIZADO, $dominio->tipo); // a própria raiz não é subdomínio
    }

    public function test_unico_ignora_um_dominio_enviado_pelo_formulario(): void
    {
        config(['tenancy.modo' => 'unico']);
        $this->comHostDaApp();

        $this->criar($this->formulario(['dominio' => 'outro.exemplo.test']))->assertSessionHasNoErrors();

        $this->assertSame([self::HOST_DA_APP], Domain::query()->pluck('dominio')->all());
    }

    public function test_unico_so_admite_uma_escola(): void
    {
        config(['tenancy.modo' => 'unico']);
        $this->comHostDaApp();

        $this->criar($this->formulario())->assertSessionHasNoErrors();
        $this->criar($this->formulario(['admin_email' => 'outra@escola.test']))->assertSessionHasErrors('modo');

        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame(1, Domain::query()->count());
    }

    public function test_unico_sem_app_url_com_host_recusa_com_erro_no_dominio(): void
    {
        config(['tenancy.modo' => 'unico', 'app.url' => '']);

        $this->criar($this->formulario())->assertSessionHasErrors('dominio');

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_unico_o_dto_aceita_dominio_ausente(): void
    {
        config(['tenancy.modo' => 'unico']);
        $this->comHostDaApp();

        $criada = app(CriarTenantAction::class)->executar(new CriarTenantDTO('Escola Nova', 'Maria', 'maria@escola.test'));

        $this->assertSame(self::HOST_DA_APP, $criada->dominio->dominio);
    }

    public function test_unico_a_pagina_nao_pede_o_dominio_e_o_modo_dominio_pede(): void
    {
        config(['tenancy.modo' => 'unico']);
        $unico = $this->noPainel('GET', '/plataforma/escolas/nova', $this->sessao, cabecalhos: $this->cabecalhosInertia());
        $this->assertTrue($unico->json('props.modoUnico'));

        config(['tenancy.modo' => 'dominio']);
        $dominio = $this->noPainel('GET', '/plataforma/escolas/nova', $this->sessao, cabecalhos: $this->cabecalhosInertia());
        $this->assertFalse($dominio->json('props.modoUnico'));
    }

    public function test_unico_o_comando_nao_pede_o_dominio(): void
    {
        config(['tenancy.modo' => 'unico']);
        $this->comHostDaApp();

        $this->artisan('mosi:tenant:create', ['--nome' => 'Escola Nova', '--admin-nome' => 'Maria', '--admin-email' => 'maria@escola.test'])->assertExitCode(0);

        $this->assertSame([self::HOST_DA_APP], Domain::query()->pluck('dominio')->all());
    }

    // --- Modo dominio -------------------------------------------------------------------------

    public function test_dominio_exige_o_dominio_principal(): void
    {
        config(['tenancy.modo' => 'dominio']);

        $this->criar($this->formulario(['dominio' => null]))->assertSessionHasErrors(['dominio' => 'Este campo é obrigatório.']);
        $this->criar($this->formulario(['dominio' => '   ']))->assertSessionHasErrors('dominio');

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_dominio_a_action_tambem_recusa_a_falta_de_dominio(): void
    {
        config(['tenancy.modo' => 'dominio']);

        try {
            app(CriarTenantAction::class)->executar(new CriarTenantDTO('Escola Nova', 'Maria', 'maria@escola.test'));
            $this->fail('Esperava DadosDeTenantInvalidos.');
        } catch (DadosDeTenantInvalidos $e) {
            $this->assertArrayHasKey('dominio', $e->erros);
        }

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_dominio_continua_a_validar_formato_reservados_e_hosts_centrais(): void
    {
        config(['tenancy.modo' => 'dominio']);

        $this->criar($this->formulario(['dominio' => 'não é um domínio']))->assertSessionHasErrors('dominio');
        $this->criar($this->formulario(['dominio' => 'admin.mositec.test']))->assertSessionHasErrors('dominio'); // subdomínio reservado
        $this->criar($this->formulario(['dominio' => self::CENTRAL]))->assertSessionHasErrors('dominio'); // host central

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_dominio_rejeita_o_host_da_propria_aplicacao(): void
    {
        config(['tenancy.modo' => 'dominio']);
        $this->comoEmProducao();

        try {
            app(CriarTenantAction::class)->executar(new CriarTenantDTO('Escola Nova', 'Maria', 'maria@escola.test', self::HOST_DA_APP));
            $this->fail('Esperava DadosDeTenantInvalidos.');
        } catch (DadosDeTenantInvalidos $e) {
            $this->assertStringContainsString('não é permitido neste ambiente', $e->erros['dominio']);
        }

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_unico_usa_o_host_da_aplicacao_sem_o_validador_mesmo_em_producao(): void
    {
        config(['tenancy.modo' => 'unico']);
        $this->comoEmProducao(); // neste ambiente o ValidadorDominio recusaria este host: a Action não o consulta no modo único

        $criada = app(CriarTenantAction::class)->executar(new CriarTenantDTO('Escola Nova', 'Maria', 'maria@escola.test'));

        $this->assertSame(self::HOST_DA_APP, $criada->dominio->dominio);
        $this->assertTrue((bool) $criada->dominio->is_principal);
    }

    public function test_unico_a_action_recusa_a_segunda_escola(): void
    {
        config(['tenancy.modo' => 'unico']);
        $this->comoEmProducao();
        app(CriarTenantAction::class)->executar(new CriarTenantDTO('Escola Nova', 'Maria', 'maria@escola.test'));

        try {
            app(CriarTenantAction::class)->executar(new CriarTenantDTO('Outra', 'Rui', 'rui@escola.test'));
            $this->fail('Esperava DadosDeTenantInvalidos.');
        } catch (DadosDeTenantInvalidos $e) {
            $this->assertArrayHasKey('modo', $e->erros);
        }

        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_dominio_cria_a_escola_com_o_dominio_indicado(): void
    {
        config(['tenancy.modo' => 'dominio']);

        $this->criar($this->formulario(['dominio' => 'lubango.mositec.test']))->assertSessionHasNoErrors();

        $this->assertSame(['lubango.mositec.test'], Domain::query()->pluck('dominio')->all());
    }
}
