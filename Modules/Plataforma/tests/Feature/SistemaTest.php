<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Tests\TestCase;

/** Página «Sistema» do painel: saúde, backups e filas, sem contexto de escola e só para o super administrador. */
class SistemaTest extends TestCase
{
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private string $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        config(['tenancy.hosts_centrais' => [self::CENTRAL], 'session.driver' => 'database', 'session.lottery' => [0, 100]]);
        // Os testes não dependem do disco de backups do desenvolvimento.
        config(['backup.monitor_backups' => [['name' => 'teste', 'disks' => ['backups'], 'health_checks' => []]]]);
        \Spatie\Backup\Config\Config::rebind();
        $this->sessao = $this->entrarNoPainel($this->superAdmin());
    }

    private function inertiaGet(string $caminho): TestResponse
    {
        return $this->noPainel('GET', $caminho, $this->sessao, cabecalhos: $this->cabecalhosInertia());
    }

    public function test_sem_sessao_vai_para_o_login(): void
    {
        $this->pedido('GET', $this->urlCentral('/plataforma/sistema'))->assertRedirect();
    }

    public function test_mostra_saude_backups_e_filas_sem_abrir_contexto_de_escola(): void
    {
        $resposta = $this->inertiaGet('/plataforma/sistema');

        $this->assertSame('Plataforma/Sistema', $resposta->json('component'));
        $this->assertIsArray($resposta->json('props.saude.verificacoes'));
        $this->assertSame('backups', $resposta->json('props.backups.0.disco'));
        $this->assertArrayHasKey('modo', $resposta->json('props.filas'));
        $this->assertFalse(app(TenantContext::class)->temTenant());
    }

    public function test_backup_desactualizado_aparece_com_o_motivo(): void
    {
        $pasta = sys_get_temp_dir().'/mosi-backups-'.uniqid();
        mkdir($pasta);
        config([
            'filesystems.disks.backups_teste' => ['driver' => 'local', 'root' => $pasta],
            'backup.monitor_backups' => [['name' => 'teste', 'disks' => ['backups_teste'], 'health_checks' => [\Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1]]],
        ]);

        \Spatie\Backup\Config\Config::rebind();
        $backup = $this->inertiaGet('/plataforma/sistema')->json('props.backups.0');
        rmdir($pasta);

        $this->assertFalse($backup['saudavel']);
        $this->assertSame(0, $backup['quantidade']);
        $this->assertNotEmpty($backup['problemas']);
        $this->assertIsString($backup['problemas'][0]);
    }

    public function test_verificar_agora_corre_as_verificacoes_e_guarda_o_resultado(): void
    {
        $this->noPainel('POST', '/plataforma/sistema/verificar', $this->sessao)->assertRedirect();

        $nomes = collect($this->inertiaGet('/plataforma/sistema')->json('props.saude.verificacoes'))->pluck('nome');

        $this->assertTrue($nomes->contains('Database'), $nomes->implode(','));
        $this->assertNotNull($this->inertiaGet('/plataforma/sistema')->json('props.saude.executado_em'));
    }

    public function test_a_navegacao_do_painel_tem_o_item_sistema(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/LayoutPlataforma.vue');

        $this->assertStringContainsString("href: '/plataforma/sistema'", $layout);
    }
}
