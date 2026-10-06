<?php

namespace Tests\Feature\CicloDeVida;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Modules\Tenant\Actions\DefinirDominioPrincipalAction;
use Modules\Tenant\Actions\EncerrarTenantAction;
use Modules\Tenant\Actions\RemoverDominioAction;
use Modules\Tenant\Exceptions\DominioNaoRemovivel;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Domain;
use RuntimeException;
use Tests\TestCase;

/** Trocar o domínio principal para outro domínio JÁ existente da mesma escola. */
class DominioPrincipalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.dominios_raiz' => ['mositec.ao']]);
        $this->tenant->dominios()->create(['dominio' => 'extra.mositec.ao', 'is_principal' => false]);
    }

    private function principais(?int $tenantId = null): array
    {
        return Domain::query()->where('tenant_id', $tenantId ?? $this->tenant->id)->where('is_principal', true)->pluck('dominio')->all();
    }

    private function definir(string $dominio): Domain
    {
        return app(DefinirDominioPrincipalAction::class)->executar($this->tenant, $dominio);
    }

    // --- Action ------------------------------------------------------------------------------

    public function test_troca_o_principal_e_fica_exactamente_um(): void
    {
        $novo = $this->definir('extra.mositec.ao');

        $this->assertSame('extra.mositec.ao', $novo->dominio);
        $this->assertTrue($novo->is_principal);
        $this->assertSame(['extra.mositec.ao'], $this->principais());
        $this->assertSame(2, $this->tenant->dominios()->count(), 'Nenhum domínio criado ou apagado.');
        $this->assertFalse(Domain::query()->where('dominio', 'localhost')->firstOrFail()->is_principal);
    }

    public function test_aceita_o_host_em_qualquer_caixa_e_com_ponto_final(): void
    {
        $this->definir('  EXTRA.Mositec.AO. ');

        $this->assertSame(['extra.mositec.ao'], $this->principais());
    }

    public function test_trocar_nao_afecta_os_dominios_de_outra_escola(): void
    {
        $outra = $this->criarTenant('MOSI-000002', 'Escola B', 'b.mositec.ao');

        $this->definir('extra.mositec.ao');

        $this->assertSame(['b.mositec.ao'], $this->principais($outra->id));
    }

    public function test_pode_voltar_ao_principal_anterior(): void
    {
        $this->definir('extra.mositec.ao');
        $this->definir('localhost');

        $this->assertSame(['localhost'], $this->principais());
    }

    public function test_recusa_dominio_inexistente_e_de_outra_escola_sem_alterar_nada(): void
    {
        $this->criarTenant('MOSI-000002', 'Escola B', 'b.mositec.ao');

        foreach (['nao-existe.mositec.ao', 'b.mositec.ao'] as $dominio) {
            try {
                $this->definir($dominio);
                $this->fail("Devia recusar '{$dominio}'.");
            } catch (OperacaoDeTenantRecusada $e) {
                $this->assertStringContainsString('não pertence', $e->getMessage());
            }
        }

        $this->assertSame(['localhost'], $this->principais());
        $this->assertSame(['b.mositec.ao'], $this->principais(Domain::where('dominio', 'b.mositec.ao')->value('tenant_id')));
    }

    public function test_recusa_o_que_ja_e_principal(): void
    {
        try {
            $this->definir('localhost');
            $this->fail('Devia recusar.');
        } catch (OperacaoDeTenantRecusada $e) {
            $this->assertStringContainsString('já é o principal', $e->getMessage());
        }

        $this->assertSame(['localhost'], $this->principais());
    }

    public function test_recusa_tenant_encerrado(): void
    {
        app(EncerrarTenantAction::class)->executar($this->tenant);

        try {
            $this->definir('extra.mositec.ao');
            $this->fail('Devia recusar.');
        } catch (TransicaoDeEstadoInvalida $e) {
            $this->assertStringContainsString('Encerrado', $e->getMessage());
        }

        $this->assertSame(['localhost'], $this->principais());
    }

    public function test_decide_pelo_estado_na_base_de_dados_e_nao_pelo_objecto_recebido(): void
    {
        $desactualizado = $this->tenant->fresh();
        app(EncerrarTenantAction::class)->executar($this->tenant);

        $this->expectException(TransicaoDeEstadoInvalida::class);

        app(DefinirDominioPrincipalAction::class)->executar($desactualizado, 'extra.mositec.ao');
    }

    public function test_e_atomica_se_a_marcacao_do_novo_falhar_o_anterior_continua_principal(): void
    {
        // Falha ao marcar o novo principal, DEPOIS de o anterior já ter sido desmarcado.
        Event::listen('eloquent.updating: '.Domain::class, function (Domain $dominio) {
            if ($dominio->is_principal === true) {
                throw new RuntimeException('falha a meio da troca');
            }
        });

        try {
            $this->definir('extra.mositec.ao');
            $this->fail('Devia propagar a falha.');
        } catch (RuntimeException $e) {
            $this->assertSame('falha a meio da troca', $e->getMessage());
        }

        $this->assertSame(['localhost'], $this->principais(), 'A troca desfez-se por inteiro: nunca fica a escola sem principal.');
    }

    public function test_o_principal_anterior_passa_a_ser_removivel_e_o_novo_deixa_de_o_ser(): void
    {
        $this->definir('extra.mositec.ao');

        try {
            app(RemoverDominioAction::class)->executar($this->tenant, 'extra.mositec.ao');
            $this->fail('O novo principal não se remove.');
        } catch (DominioNaoRemovivel $e) {
            $this->assertStringContainsString('principal', $e->getMessage());
        }

        app(RemoverDominioAction::class)->executar($this->tenant, 'localhost');

        $this->assertSame(['extra.mositec.ao'], $this->tenant->dominios()->pluck('dominio')->all());
    }

    // --- Efeito real -------------------------------------------------------------------------

    public function test_o_novo_principal_resolve_e_o_antigo_continua_a_resolver(): void
    {
        $this->get('http://extra.mositec.ao/login')->assertOk();

        $this->definir('extra.mositec.ao');

        $this->get('http://extra.mositec.ao/login')->assertOk();
        $this->get('http://localhost/login')->assertOk();
    }

    // --- Comando -----------------------------------------------------------------------------

    public function test_o_comando_troca_o_principal(): void
    {
        $this->artisan('mosi:tenant:domain:principal', ['codigo' => 'MOSI-000001', 'dominio' => 'extra.mositec.ao'])
            ->expectsOutputToContain('extra.mositec.ao')
            ->assertExitCode(0);

        $this->assertSame(['extra.mositec.ao'], $this->principais());
    }

    public function test_o_comando_recusa_com_mensagem_clara_e_codigo_1(): void
    {
        $this->assertSame(1, Artisan::call('mosi:tenant:domain:principal', ['codigo' => 'MOSI-000001', 'dominio' => 'localhost']));
        $this->assertStringContainsString('já é o principal', Artisan::output());

        $this->assertSame(1, Artisan::call('mosi:tenant:domain:principal', ['codigo' => 'MOSI-000001', 'dominio' => 'x.mositec.ao']));
        $this->assertSame(1, Artisan::call('mosi:tenant:domain:principal', ['codigo' => 'MOSI-999999', 'dominio' => 'extra.mositec.ao']));
        $this->assertSame(['localhost'], $this->principais());
    }
}
