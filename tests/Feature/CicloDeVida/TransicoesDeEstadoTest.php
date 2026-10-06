<?php

namespace Tests\Feature\CicloDeVida;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Actions\EncerrarTenantAction;
use Modules\Tenant\Actions\ReactivarTenantAction;
use Modules\Tenant\Actions\SuspenderTenantAction;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class TransicoesDeEstadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 10:00:00');
    }

    private function suspender(Tenant $tenant, string $motivo = 'Falta de pagamento'): Tenant
    {
        return app(SuspenderTenantAction::class)->executar($tenant, $motivo);
    }

    public function test_suspender_regista_estado_data_e_motivo(): void
    {
        $tenant = $this->suspender($this->tenant, '  Falta de pagamento  ');

        $bd = Tenant::findOrFail($this->tenant->id);
        $this->assertSame(EstadoTenant::SUSPENSO, $bd->estado);
        $this->assertSame('Suspenso', $bd->estado_descricao);
        $this->assertSame('Falta de pagamento', $bd->motivo_suspensao);
        $this->assertTrue($bd->suspenso_em->equalTo(Carbon::now()));
        $this->assertNull($bd->encerrado_em);
        $this->assertSame(EstadoTenant::SUSPENSO, $tenant->estado);
    }

    public function test_suspender_exige_motivo(): void
    {
        foreach (['', '   '] as $motivo) {
            try {
                $this->suspender($this->tenant, $motivo);
                $this->fail('Devia recusar o motivo vazio.');
            } catch (DadosDeTenantInvalidos $e) {
                $this->assertArrayHasKey('motivo', $e->erros);
            }
        }

        $this->assertSame(EstadoTenant::ACTIVO, Tenant::findOrFail($this->tenant->id)->estado);
    }

    public function test_suspender_recusa_motivo_maior_que_a_coluna(): void
    {
        $this->expectException(DadosDeTenantInvalidos::class);

        $this->suspender($this->tenant, str_repeat('x', 256));
    }

    public function test_suspender_duas_vezes_e_erro_e_nao_altera_o_registo(): void
    {
        $this->suspender($this->tenant, 'Primeiro');
        Carbon::setTestNow('2026-10-02 10:00:00');

        try {
            $this->suspender($this->tenant, 'Segundo');
            $this->fail('Devia recusar a repetição.');
        } catch (TransicaoDeEstadoInvalida $e) {
            $this->assertStringContainsString('Suspenso', $e->getMessage());
        }

        $bd = Tenant::findOrFail($this->tenant->id);
        $this->assertSame('Primeiro', $bd->motivo_suspensao);
        $this->assertSame('2026-10-01 10:00:00', $bd->suspenso_em->format('Y-m-d H:i:s'));
    }

    public function test_reactivar_limpa_a_suspensao(): void
    {
        $this->suspender($this->tenant);

        $tenant = app(ReactivarTenantAction::class)->executar($this->tenant);

        $bd = Tenant::findOrFail($this->tenant->id);
        $this->assertSame(EstadoTenant::ACTIVO, $bd->estado);
        $this->assertSame('Activo', $bd->estado_descricao);
        $this->assertNull($bd->suspenso_em);
        $this->assertNull($bd->motivo_suspensao);
        $this->assertSame(EstadoTenant::ACTIVO, $tenant->estado);
    }

    public function test_reactivar_um_tenant_activo_e_erro(): void
    {
        $this->expectException(TransicaoDeEstadoInvalida::class);

        app(ReactivarTenantAction::class)->executar($this->tenant);
    }

    public function test_pode_suspender_de_novo_depois_de_reactivar(): void
    {
        $this->suspender($this->tenant, 'Um');
        app(ReactivarTenantAction::class)->executar($this->tenant);
        $this->suspender($this->tenant, 'Dois');

        $this->assertSame('Dois', Tenant::findOrFail($this->tenant->id)->motivo_suspensao);
    }

    public function test_encerrar_a_partir_de_activo_regista_a_data(): void
    {
        $tenant = app(EncerrarTenantAction::class)->executar($this->tenant);

        $bd = Tenant::findOrFail($this->tenant->id);
        $this->assertSame(EstadoTenant::ENCERRADO, $bd->estado);
        $this->assertSame('Encerrado', $bd->estado_descricao);
        $this->assertTrue($bd->encerrado_em->equalTo(Carbon::now()));
        $this->assertNull($bd->suspenso_em);
        $this->assertSame(EstadoTenant::ENCERRADO, $tenant->estado);
    }

    public function test_encerrar_a_partir_de_suspenso_conserva_o_historico_da_suspensao(): void
    {
        $this->suspender($this->tenant, 'Motivo');

        app(EncerrarTenantAction::class)->executar($this->tenant);

        $bd = Tenant::findOrFail($this->tenant->id);
        $this->assertSame(EstadoTenant::ENCERRADO, $bd->estado);
        $this->assertSame('Motivo', $bd->motivo_suspensao);
        $this->assertNotNull($bd->suspenso_em);
        $this->assertNotNull($bd->encerrado_em);
    }

    public function test_encerrado_e_terminal(): void
    {
        app(EncerrarTenantAction::class)->executar($this->tenant);
        $dataOriginal = Tenant::findOrFail($this->tenant->id)->encerrado_em;
        Carbon::setTestNow('2026-10-05 10:00:00');

        foreach ([
            fn () => app(EncerrarTenantAction::class)->executar($this->tenant),
            fn () => app(ReactivarTenantAction::class)->executar($this->tenant),
            fn () => $this->suspender($this->tenant),
        ] as $transicao) {
            try {
                $transicao();
                $this->fail('Encerrado não admite transições.');
            } catch (TransicaoDeEstadoInvalida $e) {
                $this->assertStringContainsString('Encerrado', $e->getMessage());
            }
        }

        $bd = Tenant::findOrFail($this->tenant->id);
        $this->assertSame(EstadoTenant::ENCERRADO, $bd->estado);
        $this->assertTrue($bd->encerrado_em->equalTo($dataOriginal));
        $this->assertNull($bd->motivo_suspensao);
    }

    public function test_a_action_decide_pelo_estado_na_bd_e_nao_pelo_objecto_recebido(): void
    {
        $copiaAntiga = Tenant::findOrFail($this->tenant->id);
        $this->suspender($this->tenant);

        // A cópia ainda diz ACTIVO; a BD diz SUSPENSO.
        $this->assertSame(EstadoTenant::ACTIVO, $copiaAntiga->estado);
        $this->expectException(TransicaoDeEstadoInvalida::class);

        $this->suspender($copiaAntiga, 'Concorrente');
    }

    public function test_as_transicoes_nao_tocam_nos_dados_nem_no_codigo(): void
    {
        $antes = $this->estabelecimentoDeTeste()->nome;
        $dominios = $this->tenant->dominios()->pluck('dominio')->all();

        $this->suspender($this->tenant);
        app(ReactivarTenantAction::class)->executar($this->tenant);
        app(EncerrarTenantAction::class)->executar($this->tenant);

        $bd = Tenant::findOrFail($this->tenant->id);
        $this->assertSame('MOSI-000001', $bd->codigo);
        $this->assertSame($dominios, $bd->dominios()->pluck('dominio')->all());
        $this->assertSame($antes, $this->estabelecimentoDeTeste()->nome);
    }

    public function test_as_transicoes_de_um_tenant_nao_afectam_outro(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->suspender($this->tenant);

        $this->assertSame(EstadoTenant::ACTIVO, Tenant::findOrFail($b->id)->estado);
    }

    public function test_a_validacao_do_estado_e_a_gravacao_correm_dentro_de_uma_transaccao_com_bloqueio(): void
    {
        // O SQLite dos testes não distingue bloqueios; verifica-se que a leitura
        // do estado actual é feita em transacção com lockForUpdate (por leitura do código).
        foreach (['Suspender', 'Reactivar', 'Encerrar'] as $accao) {
            $codigo = file_get_contents(base_path("Modules/Tenant/app/Actions/{$accao}TenantAction.php"));

            $this->assertStringContainsString('DB::transaction', $codigo, $accao);
            $this->assertStringContainsString('lockForUpdate()', $codigo, $accao);
        }
    }
}
