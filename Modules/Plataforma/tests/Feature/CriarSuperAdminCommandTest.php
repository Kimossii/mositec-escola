<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\Provisioning\GeradorSenhaTemporaria;
use Modules\Plataforma\Models\SuperAdmin;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

class CriarSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA_ESPECIAL = 'a<b>c\\d/e</>f<info>g</info>h\\<i';

    private function opcoes(array $extra = []): array
    {
        return array_merge(['--nome' => 'Rui Operador', '--email' => 'rui@plataforma.test'], $extra);
    }

    private function senhaDe(string $output): string
    {
        $this->assertSame(1, preg_match_all('/Senha temporária: (\S+)/', $output, $m), 'A linha da senha aparece uma vez.');

        return $m[1][0];
    }

    private function forcarSenha(string $senha): void
    {
        $this->app->bind(GeradorSenhaTemporaria::class, fn () => new class($senha) extends GeradorSenhaTemporaria {
            public function __construct(private readonly string $fixa) {}

            public function gerar(): string
            {
                return $this->fixa;
            }
        });
    }

    public function test_cria_e_mostra_a_senha_uma_so_vez(): void
    {
        $codigo = Artisan::call('mosi:plataforma:admin:create', $this->opcoes());
        $output = Artisan::output();

        $this->assertSame(0, $codigo, $output);
        $senha = $this->senhaDe($output);
        $this->assertSame(1, substr_count($output, $senha));
        $this->assertStringContainsString('rui@plataforma.test', $output);
        $this->assertStringContainsString('única vez', $output);
        $this->assertStringContainsString('troca no primeiro acesso', $output);

        $admin = SuperAdmin::where('email', 'rui@plataforma.test')->sole();
        $this->assertTrue($admin->deve_alterar_senha);
        $this->assertSame(1, (int) $admin->estado);
        $this->assertTrue(password_verify($senha, $admin->password));
        $this->assertNotSame($senha, $admin->password);
    }

    public function test_a_senha_com_simbolos_de_marcacao_sai_exactamente_como_foi_gerada(): void
    {
        $this->forcarSenha(self::SENHA_ESPECIAL);

        $this->assertSame(0, Artisan::call('mosi:plataforma:admin:create', $this->opcoes()));

        $this->assertSame(self::SENHA_ESPECIAL, $this->senhaDe(Artisan::output()));
        $this->assertTrue(password_verify(self::SENHA_ESPECIAL, SuperAdmin::sole()->password));
    }

    public function test_nao_aceita_password(): void
    {
        $this->assertFalse(Artisan::all()['mosi:plataforma:admin:create']->getDefinition()->hasOption('password'));

        try {
            Artisan::call('mosi:plataforma:admin:create', $this->opcoes(['--password' => 'abc12345']));
            $this->fail('A opção --password não devia existir.');
        } catch (InvalidOptionException) {
            $this->assertSame(0, SuperAdmin::count());
        }
    }

    public function test_email_duplicado_ou_invalido_e_recusado_e_nada_se_cria(): void
    {
        $this->artisan('mosi:plataforma:admin:create', $this->opcoes())->assertSuccessful();

        // Duplicado, mesmo com outras maiúsculas e espaços.
        $this->artisan('mosi:plataforma:admin:create', $this->opcoes(['--email' => '  RUI@plataforma.test ']))
            ->expectsOutputToContain('já está registado')
            ->assertFailed();
        $this->artisan('mosi:plataforma:admin:create', $this->opcoes(['--email' => 'nao-e-email']))
            ->expectsOutputToContain('inválido')
            ->assertFailed();
        $this->artisan('mosi:plataforma:admin:create', $this->opcoes(['--nome' => '  ']))
            ->expectsQuestion('Nome do super admin', '')
            ->expectsOutputToContain('nome')
            ->assertFailed();

        $this->assertSame(1, SuperAdmin::count());
    }

    public function test_pergunta_o_que_falta(): void
    {
        $this->artisan('mosi:plataforma:admin:create')
            ->expectsQuestion('Nome do super admin', 'Eva Operadora')
            ->expectsQuestion('Email do super admin', 'eva@plataforma.test')
            ->expectsOutputToContain('Senha temporária:')
            ->assertSuccessful();

        $this->assertSame('Eva Operadora', SuperAdmin::sole()->name);
    }

    public function test_sem_interaccao_e_sem_opcoes_falha(): void
    {
        $this->assertNotSame(0, Artisan::call('mosi:plataforma:admin:create', ['--no-interaction' => true]));
        $this->assertSame(0, SuperAdmin::count());
    }

    public function test_a_senha_nao_aparece_em_nenhum_log_nem_na_auditoria(): void
    {
        $mensagens = [];
        Log::listen(function ($evento) use (&$mensagens) {
            $mensagens[] = $evento->message . json_encode($evento->context);
        });
        $this->forcarSenha(self::SENHA_ESPECIAL);

        Artisan::call('mosi:plataforma:admin:create', $this->opcoes());
        Artisan::call('mosi:plataforma:admin:create', $this->opcoes()); // falha por duplicado

        foreach ($mensagens as $mensagem) {
            $this->assertStringNotContainsString(self::SENHA_ESPECIAL, $mensagem);
        }
        $this->assertStringNotContainsString(self::SENHA_ESPECIAL, json_encode(DB::table('plataforma_auditoria')->get()));
    }

    // ---- reset ----

    public function test_reset_gera_nova_senha_temporaria_e_exige_troca(): void
    {
        $this->artisan('mosi:plataforma:admin:create', $this->opcoes())->assertSuccessful();
        $admin = SuperAdmin::sole();
        $admin->forceFill(['deve_alterar_senha' => false, 'remember_token' => 'antigo'])->save();
        $hashAntigo = $admin->password;
        $this->forcarSenha(self::SENHA_ESPECIAL);

        $this->assertSame(0, Artisan::call('mosi:plataforma:admin:reset', ['--email' => 'RUI@plataforma.test']));
        $output = Artisan::output();

        $this->assertSame(self::SENHA_ESPECIAL, $this->senhaDe($output));
        $this->assertStringContainsString('única vez', $output);
        $admin->refresh();
        $this->assertNotSame($hashAntigo, $admin->password);
        $this->assertTrue(password_verify(self::SENHA_ESPECIAL, $admin->password));
        $this->assertTrue($admin->deve_alterar_senha);
        $this->assertNotSame('antigo', $admin->remember_token);
    }

    public function test_reset_recusa_conta_desactivada_e_email_desconhecido(): void
    {
        $this->artisan('mosi:plataforma:admin:create', $this->opcoes())->assertSuccessful();
        $admin = SuperAdmin::sole();
        $admin->update(['estado' => 0]);
        $hash = $admin->fresh()->password;

        $this->artisan('mosi:plataforma:admin:reset', ['--email' => 'rui@plataforma.test'])
            ->expectsOutputToContain('desactivad')
            ->assertFailed();
        $this->artisan('mosi:plataforma:admin:reset', ['--email' => 'ninguem@plataforma.test'])
            ->expectsOutputToContain('Nenhum super admin')
            ->assertFailed();

        $this->assertSame($hash, $admin->fresh()->password);
    }

    public function test_reset_pergunta_o_email_e_nao_aceita_password(): void
    {
        $this->assertFalse(Artisan::all()['mosi:plataforma:admin:reset']->getDefinition()->hasOption('password'));
        $this->artisan('mosi:plataforma:admin:create', $this->opcoes())->assertSuccessful();

        $this->artisan('mosi:plataforma:admin:reset')
            ->expectsQuestion('Email do super admin', 'rui@plataforma.test')
            ->expectsOutputToContain('Senha temporária:')
            ->assertSuccessful();
    }

    /**
     * Decisão da Task 1: o reset NÃO apaga linhas de `sessions` por user_id. As sessões da Plataforma
     * têm user_id nulo (D5), por isso um id igual ao do super admin só pode ser de um utilizador de
     * escola, cuja sessão não se pode tocar. A invalidação efectiva fica para a Task 3.
     */
    public function test_reset_nao_apaga_sessoes_de_utilizadores_de_escola_com_o_mesmo_id(): void
    {
        $this->artisan('mosi:plataforma:admin:create', $this->opcoes())->assertSuccessful();
        $admin = SuperAdmin::sole();
        DB::table('sessions')->insert(['id' => 'sessao-de-escola', 'user_id' => $admin->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->artisan('mosi:plataforma:admin:reset', ['--email' => 'rui@plataforma.test'])->assertSuccessful();

        $this->assertDatabaseHas('sessions', ['id' => 'sessao-de-escola']);
    }
}
