<?php

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Tenant\Actions\CriarTenantAction;
use Modules\Tenant\DTO\CriarTenantDTO;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\OrdemDeProvisionamentoDuplicada;
use Modules\Tenant\Exceptions\ProvisionamentoIncompleto;
use Modules\Core\Tenancy\Provisioning\ColectorDeCredenciais;
use Modules\Usuario\Provisioning\ProvisionarTiposDocumento;
use Modules\Autenticacao\Provisioning\ProvisionarAdministradorInicial;
use Modules\Tenant\Services\GeradorCodigoTenant;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarTenantActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
    }

    private function dto(array $extra = []): CriarTenantDTO
    {
        return new CriarTenantDTO(...array_merge([
            'nomeEstabelecimento' => 'Escola Nova',
            'nomeAdministrador' => 'Ana Admin',
            'emailAdministrador' => 'ana@nova.test',
            'dominioPrincipal' => 'nova.mositec-escola.test',
        ], $extra));
    }

    private function criar(array $extra = [])
    {
        return app(CriarTenantAction::class)->executar($this->dto($extra));
    }

    /** @return array<string, int> contagens globais, para provar que nada ficou criado */
    private function contagens(): array
    {
        return [
            'tenants' => DB::table('tenants')->count(),
            'domains' => DB::table('domains')->count(),
            'estabelecimentos' => DB::table('estabelecimentos')->count(),
            'roles' => DB::table('roles')->count(),
            'role_permissoes' => DB::table('role_permissoes')->count(),
            'users' => DB::table('users')->count(),
            'tipos_documentos' => DB::table('tipos_documentos')->count(),
            'regras_cobranca' => DB::table('regras_cobranca')->count(),
        ];
    }

    // (a) contrato e etiqueta

    public function test_executa_os_provisionadores_etiquetados_por_ordem_crescente(): void
    {
        $registo = new \ArrayObject();
        $this->app->bind('prov.tarde', fn () => new ProvisionadorDeTeste(900, $registo));
        $this->app->bind('prov.cedo', fn () => new ProvisionadorDeTeste(5, $registo));
        $this->app->tag(['prov.tarde', 'prov.cedo'], ProvisionaTenant::ETIQUETA);

        $this->criar();

        $this->assertSame([5, 900], $registo->getArrayCopy());
    }

    public function test_ordem_duplicada_e_erro_explicito_e_nao_cria_nada(): void
    {
        $registo = new \ArrayObject();
        $this->app->bind('prov.a', fn () => new ProvisionadorDeTeste(20, $registo));
        $this->app->tag(['prov.a'], ProvisionaTenant::ETIQUETA);
        $antes = $this->contagens();

        try {
            $this->criar();
            $this->fail('Devia lançar OrdemDeProvisionamentoDuplicada.');
        } catch (OrdemDeProvisionamentoDuplicada $e) {
            $this->assertStringContainsString('20', $e->getMessage());
        }

        $this->assertSame($antes, $this->contagens());
        $this->assertSame([], $registo->getArrayCopy());
    }

    // (c) ponta a ponta

    public function test_cria_a_escola_de_ponta_a_ponta(): void
    {
        $criado = $this->criar();

        $this->assertSame('MOSI-000002', $criado->tenant->codigo, 'Código gerado a seguir ao do tenant de teste.');
        $this->assertSame(EstadoTenant::ACTIVO, $criado->tenant->estado);
        $this->assertSame('nova.mositec-escola.test', $criado->dominio->dominio);
        $this->assertTrue($criado->dominio->is_principal);
        $this->assertSame($criado->tenant->id, $criado->dominio->tenant_id);

        $this->noTenant($criado->tenant, function () {
            $estabelecimento = Estabelecimento::current();
            $this->assertSame('Escola Nova', $estabelecimento->nome);
            $this->assertNull($estabelecimento->configurado_em);
            $this->assertSame(count(Perfil::cases()), Role::count());
            $this->assertGreaterThan(0, RolePermissao::count());
            $this->assertSame(6, TipoDocumento::count());
            $admin = User::sole();
            $this->assertSame('ana@nova.test', $admin->email);
            $this->assertTrue($admin->deve_alterar_senha);
            $this->assertSame([Perfil::ADMIN_ESCOLA->value], $admin->roles()->pluck('nome')->all());
        });

        $this->assertNotNull($criado->credencial);
        $this->assertSame('ana@nova.test', $criado->credencial->email);
    }

    public function test_o_contexto_anterior_e_reposto_no_fim(): void
    {
        $this->criar();

        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    public function test_o_administrador_entra_com_a_senha_temporaria_e_e_forcado_a_trocar_e_depois_a_configurar(): void
    {
        $criado = $this->criar();
        $senha = $criado->credencial->senha();
        $base = 'http://nova.mositec-escola.test';

        $this->post("{$base}/login", ['login' => 'ana@nova.test', 'password' => $senha])->assertRedirect();

        // 1.º a troca da senha, antes de tudo o resto.
        $this->get("{$base}/")->assertRedirect(route('senha.alterar'));
        $this->get("{$base}/estabelecimento")->assertRedirect(route('senha.alterar'));
        $this->put("{$base}/alterar-senha", [
            'current_password' => $senha,
            'password' => 'nova-senha-segura-456',
            'password_confirmation' => 'nova-senha-segura-456',
        ])->assertRedirect();

        // 2.º a configuração inicial do estabelecimento.
        $this->get("{$base}/usuarios")->assertRedirect(route('estabelecimento.dados'));
        $this->get("{$base}/estabelecimento")->assertOk();

        $this->noTenant($criado->tenant, function () {
            $admin = User::where('email', 'ana@nova.test')->sole();
            $this->assertFalse($admin->deve_alterar_senha);
            $this->assertTrue(Hash::check('nova-senha-segura-456', $admin->password));
        });
    }

    // (d) atomicidade

    public function test_falha_no_terceiro_passo_nao_deixa_nada_criado(): void
    {
        $registo = new \ArrayObject();
        // Ordem 25: depois do estabelecimento (10) e dos perfis (20), antes dos tipos de documento (30) e do admin (40).
        $this->app->bind('prov.falha', fn () => new ProvisionadorDeTeste(25, $registo, falhar: true));
        $this->app->tag(['prov.falha'], ProvisionaTenant::ETIQUETA);
        $antes = $this->contagens();

        try {
            $this->criar();
            $this->fail('Devia propagar a falha do provisionador.');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada', $e->getMessage());
        }

        $this->assertSame([25], $registo->getArrayCopy());
        $this->assertSame($antes, $this->contagens());
        $this->assertFalse(Tenant::where('codigo', 'MOSI-000002')->exists());
        $this->assertFalse(Domain::where('dominio', 'nova.mositec-escola.test')->exists());
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    // I2: provisioning incompleto

    private function etiquetas(): array
    {
        return (new \ReflectionProperty($this->app, 'tags'))->getValue($this->app);
    }

    private function definirEtiquetas(array $tags): void
    {
        (new \ReflectionProperty($this->app, 'tags'))->setValue($this->app, $tags);
    }

    public function test_sem_provisionadores_etiquetados_lanca_e_nao_cria_nada(): void
    {
        $this->definirEtiquetas([]);
        config(['tenancy.provisionadores_esperados' => []]);
        $antes = $this->contagens();

        $this->expectException(ProvisionamentoIncompleto::class);
        try {
            $this->criar();
        } finally {
            $this->assertSame($antes, $this->contagens());
        }
    }

    public function test_provisionador_esperado_em_falta_lanca_antes_de_criar_nada(): void
    {
        $tags = $this->etiquetas();
        $tags[ProvisionaTenant::ETIQUETA] = array_values(array_filter(
            $tags[ProvisionaTenant::ETIQUETA],
            fn ($c) => $c !== ProvisionarTiposDocumento::class,
        ));
        $this->definirEtiquetas($tags);
        $antes = $this->contagens();

        try {
            $this->criar();
            $this->fail('Devia lançar ProvisionamentoIncompleto.');
        } catch (ProvisionamentoIncompleto $e) {
            $this->assertStringContainsString('ProvisionarTiposDocumento', $e->getMessage());
        }

        $this->assertSame($antes, $this->contagens());
    }

    public function test_colector_vazio_depois_dos_provisionadores_desfaz_tudo(): void
    {
        $tags = $this->etiquetas();
        $tags[ProvisionaTenant::ETIQUETA] = array_values(array_filter(
            $tags[ProvisionaTenant::ETIQUETA],
            fn ($c) => $c !== ProvisionarAdministradorInicial::class,
        ));
        $this->definirEtiquetas($tags);
        config(['tenancy.provisionadores_esperados' => array_values(array_diff(
            config('tenancy.provisionadores_esperados'),
            [ProvisionarAdministradorInicial::class],
        ))]);
        $antes = $this->contagens();

        try {
            $this->criar();
            $this->fail('Devia lançar ProvisionamentoIncompleto.');
        } catch (ProvisionamentoIncompleto $e) {
            $this->assertStringContainsString('administrador', $e->getMessage());
        }

        $this->assertSame($antes, $this->contagens());
    }

    public function test_a_credencial_nao_fica_no_colector_depois_de_uma_falha(): void
    {
        $this->app->bind('prov.cred', fn () => new class implements ProvisionaTenant {
            public function ordem(): int
            {
                return 90;
            }

            public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
            {
                app(ColectorDeCredenciais::class)->registar(new \Modules\Core\Tenancy\Provisioning\CredencialInicial('x@y.test', 'segredo'));
                throw new \RuntimeException('falha depois da credencial');
            }
        });
        $this->app->tag(['prov.cred'], ProvisionaTenant::ETIQUETA);

        try {
            $this->criar();
            $this->fail('Devia propagar.');
        } catch (\RuntimeException) {
        }

        $this->assertNull(app(ColectorDeCredenciais::class)->retirar());
    }

    public function test_conflito_de_unicidade_numa_corrida_vira_excepcao_de_dominio(): void
    {
        $this->app->bind('prov.corrida', fn () => new class implements ProvisionaTenant {
            public function ordem(): int
            {
                return 5;
            }

            public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
            {
                // Outro pedido "ganhou" o mesmo código.
                DB::table('tenants')->insert(['codigo' => 'MOSI-000555', 'nome' => 'Intruso', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->app->tag(['prov.corrida'], ProvisionaTenant::ETIQUETA);
        $antes = $this->contagens();

        try {
            $this->criar(['codigo' => 'MOSI-000555']);
            $this->fail('Devia lançar DadosDeTenantInvalidos.');
        } catch (DadosDeTenantInvalidos $e) {
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        $this->assertSame($antes, $this->contagens());
    }

    public function test_depois_de_999999_o_gerador_lanca_em_vez_de_passar_a_sete_digitos(): void
    {
        Tenant::create(['codigo' => 'MOSI-999999', 'nome' => 'Último']);

        $this->expectException(DadosDeTenantInvalidos::class);
        app(GeradorCodigoTenant::class)->proximo();
    }

    public function test_codigo_000000_e_recusado(): void
    {
        $antes = $this->contagens();

        $this->assertRecusado(['codigo' => 'MOSI-000000'], 'codigo', $antes);
    }

    public function test_fora_de_desenvolvimento_recusa_ips_localhost_uma_etiqueta_e_o_host_da_app(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://app.mositec.test']);
        $antes = $this->contagens();

        foreach (['127.0.0.1', '10.0.0.5', 'localhost', 'escola', 'app.mositec.test'] as $dominio) {
            $this->assertRecusado(['dominioPrincipal' => $dominio], 'dominio', $antes);
        }

        $this->assertSame('escola.mositec.test', $this->criar(['dominioPrincipal' => 'escola.mositec.test'])->dominio->dominio);
    }

    public function test_em_desenvolvimento_continuam_aceites_ips_e_uma_etiqueta(): void
    {
        $this->assertSame('127.0.0.9', $this->criar(['dominioPrincipal' => '127.0.0.9'])->dominio->dominio);
        $this->assertSame('escolab', $this->criar(['dominioPrincipal' => 'escolab', 'emailAdministrador' => 'b@b.test'])->dominio->dominio);
    }

    // (e) validações

    public function test_codigo_duplicado_e_recusado(): void
    {
        $antes = $this->contagens();

        $this->assertRecusado(['codigo' => 'MOSI-000001'], 'codigo', $antes);
    }

    public function test_codigo_com_formato_invalido_e_recusado(): void
    {
        $antes = $this->contagens();

        foreach (['MOSI-12', 'ABC-000009', 'mosi-000009', 'MOSI-0000099'] as $codigo) {
            $this->assertRecusado(['codigo' => $codigo], 'codigo', $antes);
        }
    }

    public function test_codigo_explicito_e_aceite(): void
    {
        $criado = $this->criar(['codigo' => 'MOSI-000777']);

        $this->assertSame('MOSI-000777', $criado->tenant->codigo);
    }

    public function test_dominio_duplicado_reservado_ou_invalido_e_recusado(): void
    {
        $antes = $this->contagens();
        config(['tenancy.hosts_centrais' => ['central.mositec.test']]);

        foreach (['localhost', 'LOCALHOST:8000', 'www.escola.test', 'API.escola.test', 'admin.escola.test', 'central.mositec.test',
            'http://escola.test', 'com espaco.test', '-x.test', 'x_y.test', '', 'a..b.test'] as $dominio) {
            $this->assertRecusado(['dominioPrincipal' => $dominio], 'dominio', $antes);
        }
    }

    public function test_dominio_e_normalizado_para_minusculas(): void
    {
        $criado = $this->criar(['dominioPrincipal' => 'Escola-Nova.Mositec.TEST:8000']);

        $this->assertSame('escola-nova.mositec.test', $criado->dominio->dominio);
    }

    public function test_email_nome_e_estabelecimento_invalidos_sao_recusados(): void
    {
        $antes = $this->contagens();

        $this->assertRecusado(['emailAdministrador' => 'nao-e-email'], 'admin_email', $antes);
        $this->assertRecusado(['emailAdministrador' => ''], 'admin_email', $antes);
        $this->assertRecusado(['nomeAdministrador' => '  '], 'admin_nome', $antes);
        $this->assertRecusado(['nomeEstabelecimento' => ''], 'nome', $antes);
    }

    public function test_modo_unico_nao_admite_um_segundo_tenant(): void
    {
        config(['tenancy.modo' => 'unico']);
        $antes = $this->contagens();

        $this->assertRecusado([], 'modo', $antes);
    }

    public function test_modo_unico_admite_o_primeiro_tenant(): void
    {
        config(['tenancy.modo' => 'unico']);
        DB::table('estabelecimentos')->delete();
        DB::table('domains')->delete();
        DB::table('tenants')->delete();

        $this->assertSame('MOSI-000001', $this->criar()->tenant->codigo);
    }

    private function assertRecusado(array $extra, string $campo, array $antes): void
    {
        try {
            $this->criar($extra);
            $this->fail('Devia recusar: ' . json_encode($extra));
        } catch (DadosDeTenantInvalidos $e) {
            $this->assertArrayHasKey($campo, $e->erros, json_encode($extra));
        }

        $this->assertSame($antes, $this->contagens());
    }

    // (f) isolamento

    public function test_duas_escolas_criadas_pela_action_ficam_isoladas_e_aceitam_o_mesmo_email_de_admin(): void
    {
        $a = $this->criar(['dominioPrincipal' => 'a.mositec.test', 'emailAdministrador' => 'admin@escola.test']);
        $b = $this->criar(['dominioPrincipal' => 'b.mositec.test', 'emailAdministrador' => 'admin@escola.test', 'nomeEstabelecimento' => 'Escola B']);

        $this->assertNotSame($a->tenant->codigo, $b->tenant->codigo);
        $this->assertNotSame($a->credencial->senha(), $b->credencial->senha());

        $idsDeA = $this->noTenant($a->tenant, fn () => [
            'roles' => Role::pluck('id')->all(),
            'users' => User::pluck('id')->all(),
            'tipos' => TipoDocumento::pluck('id')->all(),
            'permissoes' => RolePermissao::pluck('id')->all(),
        ]);

        $this->noTenant($b->tenant, function () use ($idsDeA) {
            $this->assertSame('Escola B', Estabelecimento::current()->nome);
            $this->assertSame(1, User::count());
            $this->assertSame([], array_intersect($idsDeA['roles'], Role::pluck('id')->all()));
            $this->assertSame([], array_intersect($idsDeA['users'], User::pluck('id')->all()));
            $this->assertSame([], array_intersect($idsDeA['tipos'], TipoDocumento::pluck('id')->all()));
            $this->assertSame([], array_intersect($idsDeA['permissoes'], RolePermissao::pluck('id')->all()));
            $this->assertSame(count($idsDeA['roles']), Role::count());
        });

        // O tenant de teste continua sem perfis nem utilizadores.
        $this->assertSame(0, Role::count());
        $this->assertSame(0, User::count());
    }
}

/** Provisionador falso: regista a sua ordem ao correr e pode falhar. */
class ProvisionadorDeTeste implements ProvisionaTenant
{
    public function __construct(private int $ordem, private \ArrayObject $registo, private bool $falhar = false) {}

    public function ordem(): int
    {
        return $this->ordem;
    }

    public function provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
    {
        $this->registo[] = $this->ordem;

        if ($this->falhar) {
            throw new \RuntimeException('falha simulada');
        }
    }
}
