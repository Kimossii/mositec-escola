<?php

namespace Tests\Feature\CicloDeVida;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Actions\EncerrarTenantAction;
use Modules\Tenant\Actions\ReactivarTenantAction;
use Modules\Tenant\Actions\SuspenderTenantAction;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

/** Motivo (opcional) do encerramento e data da última reactivação, gravados em `tenants`. */
class MotivoDeEncerramentoTest extends TestCase
{
    use RefreshDatabase;

    private function recarregar(): Tenant
    {
        return Tenant::findOrFail($this->tenant->id);
    }

    // --- Encerrar com e sem motivo -----------------------------------------------------------

    public function test_encerrar_com_motivo_grava_o_motivo_aparado(): void
    {
        app(EncerrarTenantAction::class)->executar($this->tenant, '  Contrato terminado  ');

        $depois = $this->recarregar();
        $this->assertSame(EstadoTenant::ENCERRADO, $depois->estado);
        $this->assertSame('Contrato terminado', $depois->motivo_encerramento);
        $this->assertNotNull($depois->encerrado_em);
    }

    public function test_o_objecto_devolvido_traz_o_motivo_gravado(): void
    {
        $devolvido = app(EncerrarTenantAction::class)->executar($this->tenant, ' Fim ');

        $this->assertSame('Fim', $devolvido->motivo_encerramento);
        $this->assertSame('Fim', $this->tenant->motivo_encerramento);
    }

    public function test_encerrar_sem_motivo_continua_a_funcionar_e_deixa_o_motivo_nulo(): void
    {
        app(EncerrarTenantAction::class)->executar($this->tenant);

        $this->assertSame(EstadoTenant::ENCERRADO, $this->recarregar()->estado);
        $this->assertNull($this->recarregar()->motivo_encerramento);
    }

    public function test_motivo_vazio_ou_so_espacos_vira_nulo(): void
    {
        foreach (['', '    ', "\n\t "] as $i => $vazio) {
            $escola = $this->criarTenant(sprintf('MOSI-0001%02d', $i), "E{$i}", "e{$i}.localhost");

            app(EncerrarTenantAction::class)->executar($escola, $vazio);

            $this->assertNull(Tenant::findOrFail($escola->id)->motivo_encerramento);
            $this->assertSame(EstadoTenant::ENCERRADO, Tenant::findOrFail($escola->id)->estado);
        }
    }

    public function test_o_tecto_de_255_caracteres_e_aplicado_pela_action_e_nada_muda(): void
    {
        try {
            app(EncerrarTenantAction::class)->executar($this->tenant, str_repeat('m', 256));
            $this->fail('Devia recusar um motivo com 256 caracteres.');
        } catch (DadosDeTenantInvalidos $e) {
            $this->assertArrayHasKey('motivo', $e->erros);
            $this->assertStringContainsString('255', $e->erros['motivo']);
        }

        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar()->estado);
        $this->assertNull($this->recarregar()->encerrado_em);

        app(EncerrarTenantAction::class)->executar($this->tenant, str_repeat('m', 255));
        $this->assertSame(255, mb_strlen($this->recarregar()->motivo_encerramento));
    }

    public function test_o_tecto_conta_caracteres_e_nao_bytes(): void
    {
        app(EncerrarTenantAction::class)->executar($this->tenant, str_repeat('ç', 255));

        $this->assertSame(255, mb_strlen($this->recarregar()->motivo_encerramento));
    }

    public function test_encerrar_uma_escola_suspensa_com_motivo_preserva_o_historico_da_suspensao(): void
    {
        app(SuspenderTenantAction::class)->executar($this->tenant, 'Falta de pagamento');

        app(EncerrarTenantAction::class)->executar($this->tenant, 'Não regularizou');

        $depois = $this->recarregar();
        $this->assertSame('Falta de pagamento', $depois->motivo_suspensao);
        $this->assertSame('Não regularizou', $depois->motivo_encerramento);
    }

    public function test_encerrar_uma_escola_ja_encerrada_continua_recusado_e_nao_sobrescreve_o_motivo(): void
    {
        app(EncerrarTenantAction::class)->executar($this->tenant, 'Primeiro');

        try {
            app(EncerrarTenantAction::class)->executar($this->tenant, 'Segundo');
            $this->fail('Devia recusar.');
        } catch (TransicaoDeEstadoInvalida) {
            // esperado
        }

        $this->assertSame('Primeiro', $this->recarregar()->motivo_encerramento);
    }

    // --- Reactivar ---------------------------------------------------------------------------

    public function test_reactivar_grava_reactivado_em(): void
    {
        $this->assertNull($this->recarregar()->reactivado_em);
        app(SuspenderTenantAction::class)->executar($this->tenant, 'X');

        app(ReactivarTenantAction::class)->executar($this->tenant);

        $depois = $this->recarregar();
        $this->assertNotNull($depois->reactivado_em);
        $this->assertLessThan(10, abs($depois->reactivado_em->diffInSeconds(now())));
        $this->assertNull($depois->suspenso_em);
        $this->assertNull($depois->motivo_suspensao);
    }

    public function test_uma_reactivacao_recusada_nao_grava_reactivado_em(): void
    {
        try {
            app(ReactivarTenantAction::class)->executar($this->tenant); // Activa
            $this->fail('Devia recusar.');
        } catch (TransicaoDeEstadoInvalida) {
            // esperado
        }

        $this->assertNull($this->recarregar()->reactivado_em);
    }

    public function test_a_ultima_reactivacao_substitui_a_anterior(): void
    {
        app(SuspenderTenantAction::class)->executar($this->tenant, 'X');
        app(ReactivarTenantAction::class)->executar($this->tenant);
        Tenant::whereKey($this->tenant->id)->update(['reactivado_em' => now()->subDays(30)]);

        app(SuspenderTenantAction::class)->executar($this->tenant->fresh(), 'Y');
        app(ReactivarTenantAction::class)->executar($this->tenant->fresh());

        $this->assertLessThan(10, abs($this->recarregar()->reactivado_em->diffInSeconds(now())));
    }

    // --- Comando -----------------------------------------------------------------------------

    public function test_o_comando_aceita_motivo_opcional(): void
    {
        $this->artisan('mosi:tenant:close', ['codigo' => 'MOSI-000001', '--force' => true, '--motivo' => 'Pedido do cliente'])
            ->expectsOutputToContain('encerrado')
            ->assertExitCode(0);

        $this->assertSame('Pedido do cliente', $this->recarregar()->motivo_encerramento);
    }

    public function test_o_comando_sem_motivo_continua_a_funcionar(): void
    {
        $this->artisan('mosi:tenant:close', ['codigo' => 'MOSI-000001', '--force' => true])->assertExitCode(0);

        $this->assertNull($this->recarregar()->motivo_encerramento);
    }

    public function test_o_comando_recusa_motivo_longo_com_mensagem_e_nada_muda(): void
    {
        $codigo = Artisan::call('mosi:tenant:close', ['codigo' => 'MOSI-000001', '--force' => true, '--motivo' => str_repeat('m', 256)]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('255', Artisan::output());
        $this->assertSame(EstadoTenant::ACTIVO, $this->recarregar()->estado);
    }

    // --- Migration ---------------------------------------------------------------------------

    private function migration(): Migration
    {
        $ficheiros = glob(base_path('Modules/Tenant/database/migrations/*_adicionar_motivo_encerramento_e_reactivado_em_a_tenants.php'));
        $this->assertCount(1, $ficheiros, 'Existe uma migration nova própria (as originais não se editam).');

        return require $ficheiros[0];
    }

    public function test_o_esquema_limpo_tem_as_colunas_novas(): void
    {
        $this->assertTrue(Schema::hasColumn('tenants', 'motivo_encerramento'));
        $this->assertTrue(Schema::hasColumn('tenants', 'reactivado_em'));
    }

    public function test_a_migration_desce_e_sobe(): void
    {
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('tenants', 'motivo_encerramento'));
        $this->assertFalse(Schema::hasColumn('tenants', 'reactivado_em'));
        $this->assertTrue(Schema::hasColumn('tenants', 'motivo_suspensao'), 'As colunas anteriores ficam.');

        $migration->up();
        $this->assertTrue(Schema::hasColumn('tenants', 'motivo_encerramento'));
        $this->assertTrue(Schema::hasColumn('tenants', 'reactivado_em'));
    }

    public function test_migrar_num_esquema_ja_existente_acrescenta_as_colunas_sem_tocar_nos_dados(): void
    {
        $migration = $this->migration();
        $migration->down(); // esquema como estava antes: já com dados
        DB::table('tenants')->where('id', $this->tenant->id)->update(['motivo_suspensao' => 'Antigo']);
        $antes = (array) DB::table('tenants')->where('id', $this->tenant->id)->first();
        $dominiosAntes = DB::table('domains')->count();

        $migration->up();

        $depois = (array) DB::table('tenants')->where('id', $this->tenant->id)->first();
        $this->assertNull($depois['motivo_encerramento']);
        $this->assertNull($depois['reactivado_em']);
        unset($depois['motivo_encerramento'], $depois['reactivado_em']);
        $this->assertSame($antes, $depois, 'Os dados existentes ficam iguais.');
        $this->assertSame($dominiosAntes, DB::table('domains')->count());
    }

    public function test_as_migrations_originais_nao_foram_editadas(): void
    {
        $original = (string) file_get_contents(base_path('Modules/Tenant/database/migrations/2026_01_01_000000_create_tenants_table.php'));

        $this->assertStringNotContainsString('motivo_encerramento', $original);
        $this->assertStringNotContainsString('reactivado_em', $original);
    }
}
