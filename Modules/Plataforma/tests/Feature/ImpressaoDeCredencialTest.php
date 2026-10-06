<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Hash;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Support\ImpressaoDeCredencial;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * O componente único que liga uma sessão da Plataforma à credencial (hash da senha) do Super Admin.
 */
class ImpressaoDeCredencialTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $senha = 'Senha-Muito-Segura-1!'): SuperAdmin
    {
        return SuperAdmin::create(['name' => 'Rui', 'email' => 'rui@plataforma.test', 'password' => Hash::make($senha)]);
    }

    private function sessao(): Store
    {
        return new Store('teste', new ArraySessionHandler(120));
    }

    public function test_a_impressao_e_um_hmac_da_credencial_com_a_chave_da_app(): void
    {
        $admin = $this->admin();

        $impressao = ImpressaoDeCredencial::para($admin);

        $this->assertSame(hash_hmac('sha256', $admin->password, (string) config('app.key')), $impressao);
        $this->assertStringNotContainsString($admin->password, $impressao, 'O hash da senha nunca vai para a sessão.');
        $this->assertNotSame($impressao, ImpressaoDeCredencial::para($this->outroAdminComOutraSenha()));

        // Outra chave de app, outra impressão.
        config(['app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);
        $this->assertNotSame($impressao, ImpressaoDeCredencial::para($admin));
    }

    private function outroAdminComOutraSenha(): SuperAdmin
    {
        return SuperAdmin::create(['name' => 'Ana', 'email' => 'ana@plataforma.test', 'password' => Hash::make('Outra-Senha-Segura-2@')]);
    }

    public function test_coincide_so_com_a_impressao_registada_da_credencial_actual(): void
    {
        $admin = $this->admin();
        $sessao = $this->sessao();

        $this->assertFalse(ImpressaoDeCredencial::coincide($sessao, $admin), 'Sem impressão registada, não coincide.');

        ImpressaoDeCredencial::registar($sessao, $admin);
        $this->assertTrue(ImpressaoDeCredencial::coincide($sessao, $admin));

        $admin->forceFill(['password' => Hash::make('Outra-Senha-Segura-2@')])->save();
        $this->assertFalse(ImpressaoDeCredencial::coincide($sessao, $admin), 'Mudou a password: a impressão deixa de coincidir.');

        ImpressaoDeCredencial::registar($sessao, $admin);
        $this->assertTrue(ImpressaoDeCredencial::coincide($sessao, $admin));
    }

    public function test_uma_impressao_de_outro_super_admin_nao_coincide(): void
    {
        $a = $this->admin();
        $b = $this->outroAdminComOutraSenha();
        $sessao = $this->sessao();
        ImpressaoDeCredencial::registar($sessao, $a);

        $this->assertFalse(ImpressaoDeCredencial::coincide($sessao, $b));
    }

    public function test_um_valor_que_nao_e_texto_na_sessao_nao_coincide(): void
    {
        $admin = $this->admin();
        $sessao = $this->sessao();
        $sessao->put(ImpressaoDeCredencial::CHAVE, ['array']);

        $this->assertFalse(ImpressaoDeCredencial::coincide($sessao, $admin));
    }

    public function test_a_logica_esta_so_neste_componente(): void
    {
        $base = module_path('Plataforma', 'app');
        $fontes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $ficheiro) {
            if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php') {
                $fontes[str_replace('\\', '/', substr($ficheiro->getPathname(), strlen($base) + 1))] = file_get_contents($ficheiro->getPathname());
            }
        }

        $com = fn (string $agulha) => array_keys(array_filter($fontes, fn ($f) => str_contains($f, $agulha)));

        $this->assertSame(['Support/ImpressaoDeCredencial.php'], $com('hash_hmac'));
        $this->assertSame(['Support/ImpressaoDeCredencial.php'], $com('plataforma.impressao'));
        // A comparação (a decisão de expulsar) só existe no middleware; os controllers não a têm.
        $this->assertSame(['Http/Middleware/SuperAdminActivo.php', 'Support/ImpressaoDeCredencial.php'], $this->ordenado($com('coincide(')));
        // Quem usa o componente: nunca um controller.
        $this->assertSame([
            'Actions/AbrirSessaoDoSuperAdminAction.php',
            'Actions/AlterarPropriaSenhaSuperAdminAction.php',
            'Http/Middleware/SuperAdminActivo.php',
            'Support/ImpressaoDeCredencial.php',
        ], $this->ordenado($com('ImpressaoDeCredencial')));
    }

    private function ordenado(array $lista): array
    {
        sort($lista);

        return $lista;
    }
}
