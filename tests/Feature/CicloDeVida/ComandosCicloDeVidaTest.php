<?php

namespace Tests\Feature\CicloDeVida;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class ComandosCicloDeVidaTest extends TestCase
{
    use RefreshDatabase;

    private function estado(): EstadoTenant
    {
        return Tenant::findOrFail($this->tenant->id)->estado;
    }

    public function test_suspender_com_motivo(): void
    {
        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'Falta de pagamento'])
            ->expectsOutputToContain('suspenso')
            ->assertExitCode(0);

        $this->assertSame(EstadoTenant::SUSPENSO, $this->estado());
        $this->assertSame('Falta de pagamento', Tenant::findOrFail($this->tenant->id)->motivo_suspensao);
    }

    public function test_suspender_sem_motivo_pergunta_e_sem_resposta_falha(): void
    {
        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-000001'])
            ->expectsQuestion('Motivo da suspensão', 'Pedido do cliente')
            ->assertExitCode(0);
        $this->assertSame('Pedido do cliente', Tenant::findOrFail($this->tenant->id)->motivo_suspensao);
    }

    public function test_suspender_sem_motivo_em_modo_nao_interactivo_falha(): void
    {
        $codigo = Artisan::call('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--no-interaction' => true]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('motivo', mb_strtolower(Artisan::output()));
        $this->assertSame(EstadoTenant::ACTIVO, $this->estado());
    }

    public function test_suspender_repetido_falha_com_mensagem_clara(): void
    {
        Artisan::call('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'X']);

        $codigo = Artisan::call('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'Y']);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Suspenso', Artisan::output());
        $this->assertSame('X', Tenant::findOrFail($this->tenant->id)->motivo_suspensao);
    }

    public function test_reactivar(): void
    {
        Artisan::call('mosi:tenant:suspend', ['codigo' => 'MOSI-000001', '--motivo' => 'X']);

        $this->artisan('mosi:tenant:reactivate', ['codigo' => 'MOSI-000001'])
            ->expectsOutputToContain('reactivado')
            ->assertExitCode(0);

        $this->assertSame(EstadoTenant::ACTIVO, $this->estado());
        $this->artisan('mosi:tenant:reactivate', ['codigo' => 'MOSI-000001'])->assertExitCode(1);
    }

    public function test_codigo_inexistente_falha(): void
    {
        $this->artisan('mosi:tenant:suspend', ['codigo' => 'MOSI-999999', '--motivo' => 'X'])
            ->expectsOutputToContain('MOSI-999999')
            ->assertExitCode(1);
        $this->artisan('mosi:tenant:reactivate', ['codigo' => 'xx'])->assertExitCode(1);
    }

    public function test_encerrar_pede_confirmacao_e_recusa_por_omissao(): void
    {
        $this->artisan('mosi:tenant:close', ['codigo' => 'MOSI-000001'])
            ->expectsConfirmation('Encerrar o tenant MOSI-000001 (Escola de Teste)? Não pode ser reaberto por este comando.', 'no')
            ->assertExitCode(1);

        $this->assertSame(EstadoTenant::ACTIVO, $this->estado());
    }

    public function test_encerrar_confirmado(): void
    {
        $this->artisan('mosi:tenant:close', ['codigo' => 'MOSI-000001'])
            ->expectsConfirmation('Encerrar o tenant MOSI-000001 (Escola de Teste)? Não pode ser reaberto por este comando.', 'yes')
            ->expectsOutputToContain('encerrado')
            ->assertExitCode(0);

        $this->assertSame(EstadoTenant::ENCERRADO, $this->estado());
    }

    public function test_encerrar_com_force_salta_a_confirmacao(): void
    {
        $this->artisan('mosi:tenant:close', ['codigo' => 'MOSI-000001', '--force' => true])->assertExitCode(0);

        $this->assertSame(EstadoTenant::ENCERRADO, $this->estado());
        $this->artisan('mosi:tenant:close', ['codigo' => 'MOSI-000001', '--force' => true])->assertExitCode(1);
    }

    public function test_encerrar_em_modo_nao_interactivo_sem_force_recusa(): void
    {
        $codigo = Artisan::call('mosi:tenant:close', ['codigo' => 'MOSI-000001', '--no-interaction' => true]);

        $this->assertSame(1, $codigo);
        $this->assertSame(EstadoTenant::ACTIVO, $this->estado());
    }

    public function test_dominio_adicionar_e_remover(): void
    {
        config(['tenancy.dominios_raiz' => ['mositec.ao']]);

        $this->artisan('mosi:tenant:domain:add', ['codigo' => 'MOSI-000001', 'dominio' => 'abc.mositec.ao'])
            ->expectsOutputToContain('abc.mositec.ao (Subdomínio)')
            ->assertExitCode(0);
        $this->assertTrue(Domain::query()->where('dominio', 'abc.mositec.ao')->exists());

        $this->artisan('mosi:tenant:domain:remove', ['codigo' => 'MOSI-000001', 'dominio' => 'abc.mositec.ao'])->assertExitCode(0);
        $this->assertFalse(Domain::query()->where('dominio', 'abc.mositec.ao')->exists());
    }

    public function test_dominio_erros_dao_exit_code_1(): void
    {
        $this->artisan('mosi:tenant:domain:add', ['codigo' => 'MOSI-000001', 'dominio' => 'www.mositec.ao'])
            ->expectsOutputToContain('reservado')
            ->assertExitCode(1);
        $this->artisan('mosi:tenant:domain:remove', ['codigo' => 'MOSI-000001', 'dominio' => 'localhost'])
            ->expectsOutputToContain('principal')
            ->assertExitCode(1);
    }

    public function test_dominio_remove_recusa_em_tenant_encerrado(): void
    {
        config(['tenancy.dominios_raiz' => ['mositec.ao']]);
        Artisan::call('mosi:tenant:domain:add', ['codigo' => 'MOSI-000001', 'dominio' => 'abc.mositec.ao']);
        Artisan::call('mosi:tenant:close', ['codigo' => 'MOSI-000001', '--force' => true]);

        $this->artisan('mosi:tenant:domain:remove', ['codigo' => 'MOSI-000001', 'dominio' => 'abc.mositec.ao'])
            ->expectsOutputToContain('Encerrado')
            ->assertExitCode(1);
        $this->assertTrue(Domain::query()->where('dominio', 'abc.mositec.ao')->exists());
    }
}
