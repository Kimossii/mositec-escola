<?php

namespace Tests\Feature\Comandos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Provisioning\GeradorSenhaTemporaria;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RecuperarAdministradorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA_COM_SIMBOLOS = 'a<b>c\\d/e</>f<info>g</info>h\\<i';

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        $this->a = $this->criarEscola('MOSI-000010', 'a.mositec.test', 'admin@a.test');
        $this->b = $this->criarEscola('MOSI-000011', 'b.mositec.test', 'admin@b.test');
    }

    private function criarEscola(string $codigo, string $dominio, string $email): Tenant
    {
        $this->assertSame(0, Artisan::call('mosi:tenant:create', [
            '--nome' => "Escola {$codigo}",
            '--admin-nome' => 'Admin',
            '--admin-email' => $email,
            '--dominio' => $dominio,
            '--codigo' => $codigo,
        ]), Artisan::output());

        return Tenant::where('codigo', $codigo)->sole();
    }

    private function admin(Tenant $tenant, string $email): User
    {
        return $this->noTenant($tenant, fn () => User::where('email', $email)->sole());
    }

    private function forcarSenha(string $senha): void
    {
        $this->app->bind(GeradorSenhaTemporaria::class, fn () => new class($senha) extends GeradorSenhaTemporaria {
            public function __construct(private string $fixa) {}

            public function gerar(): string
            {
                return $this->fixa;
            }
        });
    }

    private function senhaDe(string $output): string
    {
        $this->assertSame(1, preg_match_all('/Senha temporária: (\S+)/', $output, $m), 'A linha da senha aparece uma vez.');

        return $m[1][0];
    }

    public function test_gera_nova_senha_mostrada_uma_vez_com_hash_flag_e_sessoes_invalidadas(): void
    {
        $admin = $this->admin($this->a, 'admin@a.test');
        $hashAntigo = $admin->password;
        $this->noTenant($this->a, fn () => $admin->forceFill(['deve_alterar_senha' => false, 'remember_token' => 'antigo'])->save());
        $this->noTenant($this->a, fn () => $admin->createToken('api'));
        DB::table('sessions')->insert(['id' => 'sessao-a', 'user_id' => $admin->id, 'payload' => 'x', 'last_activity' => time()]);

        $codigo = Artisan::call('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010']);
        $output = Artisan::output();

        $this->assertSame(0, $codigo, $output);
        $senha = $this->senhaDe($output);
        $this->assertSame(1, substr_count($output, $senha));
        $this->assertStringContainsString('admin@a.test', $output);
        $this->assertStringContainsString('única vez', $output);

        $depois = $this->admin($this->a, 'admin@a.test');
        $this->assertNotSame($hashAntigo, $depois->password);
        $this->assertTrue(Hash::check($senha, $depois->password));
        $this->assertTrue($depois->deve_alterar_senha);
        $this->assertNull($depois->senha_redefinida_por);
        $this->assertNotNull($depois->senha_redefinida_em);
        $this->assertNotSame('antigo', $depois->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count());
        $this->assertSame(0, $this->noTenant($this->a, fn () => $depois->tokens()->count()));
    }

    public function test_senha_com_simbolos_de_marcacao_sai_exactamente_como_foi_gerada(): void
    {
        $this->forcarSenha(self::SENHA_COM_SIMBOLOS);

        $this->assertSame(0, Artisan::call('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010']));

        $this->assertSame(self::SENHA_COM_SIMBOLOS, $this->senhaDe(Artisan::output()));
        $this->assertTrue(Hash::check(self::SENHA_COM_SIMBOLOS, $this->admin($this->a, 'admin@a.test')->password));
    }

    public function test_o_outro_tenant_fica_intacto(): void
    {
        $adminB = $this->admin($this->b, 'admin@b.test');
        DB::table('sessions')->insert(['id' => 'sessao-b', 'user_id' => $adminB->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->artisan('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010'])->assertSuccessful();

        $depois = $this->admin($this->b, 'admin@b.test');
        $this->assertSame($adminB->password, $depois->password);
        $this->assertSame($adminB->remember_token, $depois->remember_token);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $adminB->id)->count());
    }

    public function test_recusa_tenant_nao_activo_sem_alterar_nada(): void
    {
        $hash = $this->admin($this->a, 'admin@a.test')->password;
        $this->a->update(['estado' => EstadoTenant::SUSPENSO]);

        $this->artisan('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010'])
            ->expectsOutputToContain('suspenso')
            ->assertFailed();

        $this->assertSame($hash, $this->admin($this->a, 'admin@a.test')->password);
    }

    public function test_exige_tenant_e_rejeita_todos(): void
    {
        $this->artisan('mosi:tenant:admin:reset')->expectsOutputToContain('--tenant')->assertFailed();
        $this->artisan('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-999999'])->assertFailed();
        $this->assertFalse(Artisan::all()['mosi:tenant:admin:reset']->getDefinition()->hasOption('todos'));
    }

    public function test_com_varios_administradores_exige_o_email(): void
    {
        $this->noTenant($this->a, function () {
            $segundo = User::create(['name' => 'Segundo', 'email' => 'segundo@a.test', 'password' => 'x', 'tipo_login' => TipoLogin::EMAIL, 'estado' => 1]);
            $segundo->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->sole()->id);
        });
        $hashPrimeiro = $this->admin($this->a, 'admin@a.test')->password;

        $this->artisan('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010'])
            ->expectsOutputToContain('--email')
            ->assertFailed();
        $this->assertSame($hashPrimeiro, $this->admin($this->a, 'admin@a.test')->password);

        $this->artisan('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010', '--email' => 'segundo@a.test'])
            ->assertSuccessful();
        $this->assertSame($hashPrimeiro, $this->admin($this->a, 'admin@a.test')->password, 'Só o escolhido muda.');
    }

    public function test_email_que_nao_e_de_administrador_e_recusado(): void
    {
        $this->artisan('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010', '--email' => 'admin@b.test'])
            ->expectsOutputToContain('Nenhum administrador')
            ->assertFailed();
    }

    public function test_a_senha_nao_aparece_em_nenhum_log(): void
    {
        $mensagens = [];
        Log::listen(function ($evento) use (&$mensagens) {
            $mensagens[] = $evento->message . json_encode($evento->context);
        });
        $this->forcarSenha(self::SENHA_COM_SIMBOLOS);

        Artisan::call('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010']);
        Artisan::call('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010', '--email' => 'inexistente@x.test']);

        foreach ($mensagens as $mensagem) {
            $this->assertStringNotContainsString(self::SENHA_COM_SIMBOLOS, $mensagem);
            $this->assertStringNotContainsString(substr(self::SENHA_COM_SIMBOLOS, 0, 8), $mensagem);
        }
    }

    public function test_o_email_e_comparado_sem_distinguir_maiusculas(): void
    {
        $hash = $this->admin($this->a, 'admin@a.test')->password;

        $this->artisan('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010', '--email' => ' ADMIN@A.test '])->assertSuccessful();

        $this->assertNotSame($hash, $this->admin($this->a, 'admin@a.test')->password);
    }

    public function test_administrador_desactivado_nao_tem_a_senha_reposta(): void
    {
        $admin = $this->admin($this->a, 'admin@a.test');
        $this->noTenant($this->a, fn () => $admin->forceFill(['estado' => 0])->save());

        $this->artisan('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010'])
            ->expectsOutputToContain('desactivado')
            ->assertFailed();

        $this->assertSame($admin->password, $this->admin($this->a, 'admin@a.test')->password);
    }

    public function test_um_administrador_desactivado_nao_conta_para_a_ambiguidade(): void
    {
        $this->noTenant($this->a, function () {
            $segundo = User::create(['name' => 'Segundo', 'email' => 'segundo@a.test', 'password' => 'x', 'tipo_login' => TipoLogin::EMAIL, 'estado' => 1]);
            $segundo->forceFill(['estado' => 0])->save();
            $segundo->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->sole()->id);
        });

        $this->artisan('mosi:tenant:admin:reset', ['--tenant' => 'MOSI-000010'])->assertSuccessful();
    }
}
