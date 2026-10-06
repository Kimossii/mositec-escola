<?php

namespace Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Tenant\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\Tenancy\JobDeTeste;
use Tests\Fixtures\Tenancy\JobSemTrait;
use Tests\TestCase;

class ComTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    /** @var list<array{nivel: string, mensagem: string, contexto: array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        JobDeTeste::$execucoes = [];
        $this->a = $this->criarTenant('MOSI-000010', 'Escola A', 'a.localhost');
        $this->b = $this->criarTenant('MOSI-000011', 'Escola B', 'b.localhost');

        Log::listen(function ($evento) {
            $this->logs[] = ['nivel' => $evento->level, 'mensagem' => $evento->message, 'contexto' => $evento->context];
        });
    }

    private function contexto(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** Passa a fila para a base de dados: o despacho só serializa, quem executa é o worker. */
    private function usarFilaDeBaseDeDados(): void
    {
        config(['queue.default' => 'database']);
    }

    private function processarFila(): void
    {
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);
    }

    private function mudarEstado(Tenant $tenant, EstadoTenant $estado): void
    {
        $tenant->update(['estado' => $estado]);
    }

    public function test_despacho_sem_contexto_falha(): void
    {
        $this->contexto()->limpar();

        $this->expectException(TenantNaoResolvido::class);

        Queue::push(new JobDeTeste());
    }

    public function test_despacho_sem_contexto_nao_deixa_nada_na_fila(): void
    {
        $this->usarFilaDeBaseDeDados();
        $this->contexto()->limpar();

        try {
            Queue::push(new JobDeTeste());
            $this->fail('O despacho sem tenant devia falhar.');
        } catch (TenantNaoResolvido) {
            $this->assertSame(0, DB::table('jobs')->count());
        }
    }

    public function test_captura_o_id_do_tenant_no_payload_sem_objecto_tenant(): void
    {
        $this->usarFilaDeBaseDeDados();

        $this->noTenant($this->a, fn () => Queue::push(new JobDeTeste(false, Estabelecimento::current())));

        $payload = DB::table('jobs')->value('payload');
        $this->assertSame($this->a->id, json_decode($payload, true)['tenant_id']);
        $this->assertStringNotContainsString('Modules\\\\Tenant', $payload);
        $this->assertStringNotContainsString('Escola A', $payload);
        $this->assertStringNotContainsString('MOSI-000010', $payload);
    }

    public function test_cada_job_reentra_no_tenant_que_o_despachou_no_mesmo_worker(): void
    {
        $this->usarFilaDeBaseDeDados();

        foreach ([$this->a, $this->b, $this->a, $this->b] as $tenant) {
            $this->noTenant($tenant, fn () => Queue::push(new JobDeTeste()));
        }
        $this->contexto()->limpar();

        $this->processarFila();

        $this->assertSame([
            ['tenant' => 'MOSI-000010', 'estabelecimento' => 'Escola A', 'modelo' => null],
            ['tenant' => 'MOSI-000011', 'estabelecimento' => 'Escola B', 'modelo' => null],
            ['tenant' => 'MOSI-000010', 'estabelecimento' => 'Escola A', 'modelo' => null],
            ['tenant' => 'MOSI-000011', 'estabelecimento' => 'Escola B', 'modelo' => null],
        ], JobDeTeste::$execucoes);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_models_com_scope_no_job_sao_restaurados_dentro_do_tenant(): void
    {
        $this->usarFilaDeBaseDeDados();

        $this->noTenant($this->a, fn () => Queue::push(new JobDeTeste(false, Estabelecimento::current())));
        $this->noTenant($this->b, fn () => Queue::push(new JobDeTeste(false, Estabelecimento::current())));
        $this->contexto()->limpar();

        $this->processarFila();

        $this->assertSame(['Escola A', 'Escola B'], array_column(JobDeTeste::$execucoes, 'modelo'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_cadeia_de_jobs_mantem_o_tenant_em_cada_elo(): void
    {
        $this->usarFilaDeBaseDeDados();

        // Sem devolver o PendingDispatch: despacha-se ao destruí-lo, que tem de ser dentro do tenant.
        $this->noTenant($this->a, function () {
            JobDeTeste::withChain([new JobDeTeste(), new JobDeTeste()])->dispatch();
        });
        $this->noTenant($this->b, function () {
            JobDeTeste::dispatch();
        });
        $this->contexto()->limpar();

        $this->processarFila();

        $this->assertSame(
            ['MOSI-000010', 'MOSI-000011', 'MOSI-000010', 'MOSI-000010'],
            array_column(JobDeTeste::$execucoes, 'tenant'),
        );
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    #[DataProvider('estadosForaDeServico')]
    public function test_tenant_nao_activo_descarta_o_job_e_regista(EstadoTenant $estado): void
    {
        $this->usarFilaDeBaseDeDados();

        $this->noTenant($this->a, fn () => Queue::push(new JobDeTeste(false, Estabelecimento::current())));
        $this->noTenant($this->b, fn () => Queue::push(new JobDeTeste()));
        $this->mudarEstado($this->a, $estado);
        $this->contexto()->limpar();

        $this->processarFila();

        // O de A nunca corre (nem lhe restaura os models); o de B corre normalmente.
        $this->assertSame(['MOSI-000011'], array_column(JobDeTeste::$execucoes, 'tenant'));
        $this->assertSame(0, DB::table('jobs')->count(), 'Descartado: não fica na fila.');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Descartado: não falha nem repete.');

        $avisos = array_values(array_filter($this->logs, fn ($l) => $l['nivel'] === 'warning' && $l['contexto']['tenant_id'] === $this->a->id));
        $this->assertNotEmpty($avisos);
        $this->assertSame(JobDeTeste::class, $avisos[0]['contexto']['job']);
        $this->assertSame(strtolower($estado->name), $avisos[0]['contexto']['motivo']);
    }

    public static function estadosForaDeServico(): array
    {
        return [
            'suspenso' => [EstadoTenant::SUSPENSO],
            'encerrado' => [EstadoTenant::ENCERRADO],
        ];
    }

    public function test_tenant_inexistente_descarta_o_job_e_regista(): void
    {
        $this->usarFilaDeBaseDeDados();

        $this->noTenant($this->a, fn () => Queue::push(new JobDeTeste()));
        DB::table('jobs')->update(['payload' => str_replace(
            '"tenant_id":' . $this->a->id . ',',
            '"tenant_id":99999,',
            DB::table('jobs')->value('payload'),
        )]);
        $this->contexto()->limpar();

        $this->processarFila();

        $this->assertSame([], JobDeTeste::$execucoes);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $aviso = collect($this->logs)->firstWhere('contexto.tenant_id', 99999);
        $this->assertSame('warning', $aviso['nivel']);
        $this->assertSame('inexistente', $aviso['contexto']['motivo']);
    }

    public function test_o_registo_do_descarte_nao_tem_dados_sensiveis(): void
    {
        $this->usarFilaDeBaseDeDados();
        $this->noTenant($this->a, fn () => Queue::push(new JobDeTeste()));
        $this->mudarEstado($this->a, EstadoTenant::SUSPENSO);
        $this->contexto()->limpar();

        $this->processarFila();

        $aviso = collect($this->logs)->firstWhere('nivel', 'warning');
        $this->assertEqualsCanonicalizing(['tenant_id', 'job', 'motivo'], array_keys($aviso['contexto']));
    }

    public function test_o_contexto_e_reposto_depois_do_job_mesmo_com_excepcao(): void
    {
        $this->noTenant($this->a, function () {
            $estabelecimentoDeA = Estabelecimento::current();

            // Sync: o job de B corre dentro do contexto de A.
            $this->noTenant($this->b, fn () => Queue::push(new JobDeTeste()));
            $this->assertSame('MOSI-000010', $this->contexto()->atual()->codigo);
            $this->assertSame($estabelecimentoDeA->id, Estabelecimento::current()->id);

            try {
                $this->noTenant($this->b, fn () => Queue::push(new JobDeTeste(falhar: true)));
                $this->fail('A excepção do job devia propagar-se.');
            } catch (RuntimeException) {
                $this->assertSame('MOSI-000010', $this->contexto()->atual()->codigo);
                $this->assertSame($estabelecimentoDeA->id, Estabelecimento::current()->id);
            }
        });

        $this->assertSame(['MOSI-000011', 'MOSI-000011'], array_column(JobDeTeste::$execucoes, 'tenant'));
    }

    public function test_job_sem_a_trait_falha_com_tenant_nao_resolvido(): void
    {
        $this->contexto()->limpar();

        $this->expectException(TenantNaoResolvido::class);

        Queue::push(new JobSemTrait());
    }

    public function test_job_sem_a_trait_numa_fila_real_vai_para_os_falhados(): void
    {
        $this->usarFilaDeBaseDeDados();
        $this->contexto()->limpar();
        Queue::push(new JobSemTrait());

        $this->processarFila();

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertStringContainsString(TenantNaoResolvido::class, DB::table('failed_jobs')->value('exception'));
    }
}
