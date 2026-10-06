<?php

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\Provisioning\GeradorSenhaTemporaria;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

class CriarTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
    }

    private function opcoes(array $extra = []): array
    {
        return array_merge([
            '--nome' => 'Escola Comando',
            '--admin-nome' => 'Rui Admin',
            '--admin-email' => 'rui@comando.test',
            '--dominio' => 'comando.mositec.test',
        ], $extra);
    }

    /** Extrai a senha temporária do output. */
    private function senhaDe(string $output): string
    {
        $this->assertSame(1, preg_match_all('/Senha temporária: (\S+)/', $output, $m), 'A linha da senha aparece uma vez.');

        return $m[1][0];
    }

    public function test_opcoes_completas_criam_o_tenant_e_mostram_a_senha_uma_so_vez(): void
    {
        $codigo = Artisan::call('mosi:tenant:create', $this->opcoes());
        $output = Artisan::output();

        $this->assertSame(0, $codigo, $output);
        $senha = $this->senhaDe($output);
        $this->assertSame(1, substr_count($output, $senha), 'A senha aparece exactamente uma vez.');
        $this->assertStringContainsString('MOSI-000002', $output);
        $this->assertStringContainsString('comando.mositec.test', $output);
        $this->assertStringContainsString('rui@comando.test', $output);
        $this->assertStringContainsString('única vez', $output);
        $this->assertStringContainsString('troca no primeiro acesso', $output);

        $tenant = Tenant::where('codigo', 'MOSI-000002')->sole();
        $this->noTenant($tenant, fn () => $this->assertTrue(
            password_verify($senha, User::where('email', 'rui@comando.test')->sole()->password),
        ));
    }

    public function test_aceita_codigo_explicito(): void
    {
        $this->artisan('mosi:tenant:create', $this->opcoes(['--codigo' => 'MOSI-000321']))->assertSuccessful();

        $this->assertTrue(Tenant::where('codigo', 'MOSI-000321')->exists());
    }

    public function test_nao_aceita_password(): void
    {
        $antes = Tenant::count();

        $this->assertFalse(Artisan::all()['mosi:tenant:create']->getDefinition()->hasOption('password'));

        try {
            Artisan::call('mosi:tenant:create', $this->opcoes(['--password' => 'abc12345']));
            $this->fail('A opção --password não devia existir.');
        } catch (InvalidOptionException) {
            $this->assertSame($antes, Tenant::count());
        }
    }

    public function test_entrada_invalida_falha_com_mensagem_clara_e_nao_cria_nada(): void
    {
        $antes = DB::table('tenants')->count();

        $this->artisan('mosi:tenant:create', $this->opcoes(['--admin-email' => 'nao-e-email']))
            ->expectsOutputToContain('email do administrador é inválido')
            ->assertFailed();
        $this->artisan('mosi:tenant:create', $this->opcoes(['--dominio' => 'www.escola.test']))
            ->expectsOutputToContain('reservado')
            ->assertFailed();
        $this->artisan('mosi:tenant:create', $this->opcoes(['--dominio' => 'localhost']))
            ->expectsOutputToContain('já está registado')
            ->assertFailed();
        $this->artisan('mosi:tenant:create', $this->opcoes(['--codigo' => 'MOSI-000001']))
            ->expectsOutputToContain('já existe')
            ->assertFailed();

        $this->assertSame($antes, DB::table('tenants')->count());
    }

    public function test_pergunta_o_que_falta(): void
    {
        $this->artisan('mosi:tenant:create')
            ->expectsQuestion('Nome do estabelecimento', 'Escola Interactiva')
            ->expectsQuestion('Nome do administrador', 'Eva Admin')
            ->expectsQuestion('Email do administrador', 'eva@interactiva.test')
            ->expectsQuestion('Domínio principal', 'interactiva.mositec.test')
            ->expectsOutputToContain('Senha temporária:')
            ->assertSuccessful();

        $this->assertSame('Escola Interactiva', Tenant::where('codigo', 'MOSI-000002')->sole()->nome);
    }

    public function test_pergunta_so_o_que_falta(): void
    {
        $this->artisan('mosi:tenant:create', ['--nome' => 'Escola Parcial', '--admin-nome' => 'Eva', '--admin-email' => 'eva@parcial.test'])
            ->expectsQuestion('Domínio principal', 'parcial.mositec.test')
            ->assertSuccessful();
    }

    public function test_sem_interaccao_e_sem_opcoes_falha(): void
    {
        $this->assertNotSame(0, Artisan::call('mosi:tenant:create', ['--no-interaction' => true]));
    }

    public function test_a_senha_nao_aparece_em_nenhum_log(): void
    {
        $mensagens = [];
        Log::listen(function ($evento) use (&$mensagens) {
            $mensagens[] = $evento->message . json_encode($evento->context);
        });

        Artisan::call('mosi:tenant:create', $this->opcoes());
        $senha = $this->senhaDe(Artisan::output());

        // Falha a meio: também não pode vazar nada para os logs.
        Artisan::call('mosi:tenant:create', $this->opcoes(['--dominio' => 'comando.mositec.test']));

        foreach ($mensagens as $mensagem) {
            $this->assertStringNotContainsString($senha, $mensagem);
        }
    }

    public function test_a_senha_com_simbolos_de_marcacao_sai_exactamente_como_foi_gerada(): void
    {
        $senha = 'a<b>c\\d/e</>f<info>g</info>h\\<i';
        $this->app->bind(GeradorSenhaTemporaria::class, fn () => new class extends GeradorSenhaTemporaria {
            public function gerar(): string
            {
                return 'a<b>c\\d/e</>f<info>g</info>h\\<i';
            }
        });

        $this->assertSame(0, Artisan::call('mosi:tenant:create', $this->opcoes()));

        $this->assertSame($senha, $this->senhaDe(Artisan::output()));
        $tenant = Tenant::where('codigo', 'MOSI-000002')->sole();
        $this->noTenant($tenant, fn () => $this->assertTrue(
            password_verify($senha, User::where('email', 'rui@comando.test')->sole()->password),
        ));
    }

    public function test_sem_provisionadores_o_comando_falha_e_nao_cria_nada(): void
    {
        $this->limparEtiquetas();
        config(['tenancy.provisionadores_esperados' => []]);
        $antes = DB::table('tenants')->count();

        $this->assertNotSame(0, Artisan::call('mosi:tenant:create', $this->opcoes()));

        $this->assertStringContainsString('provisionador', Artisan::output());
        $this->assertSame($antes, DB::table('tenants')->count());
    }

    public function test_excepcao_inesperada_nao_mostra_bindings_nem_mensagem(): void
    {
        $this->app->bind('prov.sql', fn () => new class implements \Modules\Core\Tenancy\Contracts\ProvisionaTenant {
            public function ordem(): int
            {
                return 5;
            }

            public function provisionar(\Modules\Core\Tenancy\TenantAtual $tenant, \Modules\Core\Tenancy\Provisioning\DadosProvisionamento $dados): void
            {
                throw new \Illuminate\Database\QueryException('sqlite', 'select 1', ['SEGREDO-BINDING'], new \Exception('boom'));
            }
        });
        $this->app->tag(['prov.sql'], \Modules\Core\Tenancy\Contracts\ProvisionaTenant::ETIQUETA);

        $this->assertNotSame(0, Artisan::call('mosi:tenant:create', $this->opcoes()));

        $output = Artisan::output();
        $this->assertStringNotContainsString('SEGREDO-BINDING', $output);
        $this->assertStringNotContainsString('select 1', $output);
        $this->assertStringContainsString('QueryException', $output);
    }

    public function test_codigo_zero_e_recusado(): void
    {
        $this->artisan('mosi:tenant:create', $this->opcoes(['--codigo' => 'MOSI-000000']))->assertFailed();
    }

    private function limparEtiquetas(): void
    {
        $r = new \ReflectionProperty($this->app, 'tags');
        $r->setValue($this->app, []);
    }
}
