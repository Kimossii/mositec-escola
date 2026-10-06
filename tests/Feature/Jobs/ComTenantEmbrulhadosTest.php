<?php

namespace Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Exception;
use Illuminate\Contracts\Queue\Job as JobContrato;
use LogicException;
use Mockery;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\Jobs\ExecutorDeJobsDeTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Models\Tenant;
use stdClass;
use Tests\Fixtures\Tenancy\JobDeLoteDeTeste;
use Tests\Fixtures\Tenancy\JobDeTeste;
use Tests\Fixtures\Tenancy\JobUnicoDeTeste;
use Tests\Fixtures\Tenancy\ListenerDeTeste;
use Tests\Fixtures\Tenancy\ListenerSemTrait;
use Tests\Fixtures\Tenancy\MailableDeTeste;
use Tests\Fixtures\Tenancy\NotificacaoDeTeste;
use Tests\TestCase;

class ComTenantEmbrulhadosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        JobDeTeste::$execucoes = [];
        ListenerDeTeste::$vistos = [];
        NotificacaoDeTeste::$vistos = [];
        $this->a = $this->criarTenant('MOSI-000010', 'Escola A', 'a.localhost');
        $this->b = $this->criarTenant('MOSI-000011', 'Escola B', 'b.localhost');
    }

    private function filaDeBaseDeDados(): void
    {
        config(['queue.default' => 'database']);
    }

    private function processarFila(): void
    {
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);
    }

    public function test_listener_em_fila_corre_no_tenant_que_o_despachou(): void
    {
        $this->filaDeBaseDeDados();
        Event::listen(stdClass::class, ListenerDeTeste::class);

        $this->noTenant($this->a, function () { Event::dispatch(new stdClass()); });
        $this->noTenant($this->b, function () { Event::dispatch(new stdClass()); });
        app(TenantContext::class)->limpar();
        $this->processarFila();

        $this->assertSame(['Escola A', 'Escola B'], ListenerDeTeste::$vistos);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_listener_em_fila_sem_a_trait_falha_com_tenant_nao_resolvido(): void
    {
        Event::listen(stdClass::class, ListenerSemTrait::class);
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        Event::dispatch(new stdClass());
    }

    public function test_listener_com_a_trait_sem_contexto_falha_no_despacho(): void
    {
        $this->filaDeBaseDeDados();
        Event::listen(stdClass::class, ListenerDeTeste::class);
        app(TenantContext::class)->limpar();

        try {
            Event::dispatch(new stdClass());
            $this->fail('O despacho sem tenant devia falhar.');
        } catch (TenantNaoResolvido) {
            $this->assertSame(0, DB::table('jobs')->count());
        }
    }

    public function test_listener_de_tenant_suspenso_e_descartado(): void
    {
        $this->filaDeBaseDeDados();
        Event::listen(stdClass::class, ListenerDeTeste::class);
        $this->noTenant($this->a, function () { Event::dispatch(new stdClass()); });
        $this->a->update(['estado' => EstadoTenant::SUSPENSO]);
        app(TenantContext::class)->limpar();

        $this->processarFila();

        $this->assertSame([], ListenerDeTeste::$vistos);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_mailable_em_fila_corre_no_tenant_que_o_despachou(): void
    {
        $this->filaDeBaseDeDados();

        $this->noTenant($this->a, function () { Mail::to('x@a.test')->send(new MailableDeTeste()); });
        $this->noTenant($this->b, function () { Mail::to('x@b.test')->send(new MailableDeTeste()); });
        app(TenantContext::class)->limpar();
        $this->processarFila();

        $assuntos = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage()->getSubject())->all();
        $this->assertSame(['Escola A', 'Escola B'], $assuntos);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_mailable_sem_contexto_falha_no_despacho(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        Mail::to('x@a.test')->send(new MailableDeTeste());
    }

    public function test_notification_em_fila_corre_no_tenant_que_o_despachou(): void
    {
        $this->filaDeBaseDeDados();

        $this->noTenant($this->a, function () { Notification::route('mail', 'x@a.test')->notify(new NotificacaoDeTeste()); });
        $this->noTenant($this->b, function () { Notification::route('mail', 'x@b.test')->notify(new NotificacaoDeTeste()); });
        app(TenantContext::class)->limpar();
        $this->processarFila();

        $this->assertSame(['Escola A', 'Escola B'], NotificacaoDeTeste::$vistos);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_notification_sem_contexto_falha_no_despacho(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        Notification::route('mail', 'x@a.test')->notify(new NotificacaoDeTeste());
    }

    public function test_closure_em_fila_e_proibida_com_mensagem_clara(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Closures em fila não são suportadas');

        dispatch(function () {});
    }

    public function test_dispatch_after_response_falha_alto_e_claro(): void
    {
        $this->noTenant($this->a, function () { JobDeTeste::dispatchAfterResponse(); });
        // Como no pedido real: o contexto já foi limpo quando a aplicação termina.
        app(TenantContext::class)->limpar();

        try {
            $this->app->terminate();
            $this->fail('dispatchAfterResponse devia falhar.');
        } catch (TenantNaoResolvido $e) {
            $this->assertStringContainsString('dispatchAfterResponse', $e->getMessage());
        }
        $this->assertSame([], JobDeTeste::$execucoes);
    }

    public function test_job_unico_descartado_liberta_o_lock(): void
    {
        $this->filaDeBaseDeDados();
        $this->noTenant($this->a, function () { JobUnicoDeTeste::dispatch(); });
        $this->assertSame(1, DB::table('jobs')->count());
        $this->a->update(['estado' => EstadoTenant::SUSPENSO]);
        app(TenantContext::class)->limpar();

        $this->processarFila();
        $this->assertSame(0, DB::table('jobs')->count());

        $this->a->update(['estado' => EstadoTenant::ACTIVO]);
        $this->noTenant($this->a, function () { JobUnicoDeTeste::dispatch(); });

        $this->assertSame(1, DB::table('jobs')->count(), 'O lock foi libertado: o novo despacho é aceite.');
    }

    public function test_job_de_lote_descartado_deixa_o_lote_cancelado_e_contado_como_falhado(): void
    {
        $this->filaDeBaseDeDados();
        $lote = $this->noTenant($this->a, fn () => Bus::batch([new JobDeLoteDeTeste()])->dispatch());
        $this->a->update(['estado' => EstadoTenant::SUSPENSO]);
        app(TenantContext::class)->limpar();

        $this->processarFila();

        $depois = Bus::findBatch($lote->id);
        $this->assertSame(1, $depois->failedJobs);
        $this->assertTrue($depois->cancelled());
        $this->assertTrue($depois->finished());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_failed_saltado_por_tenant_nao_activo_regista_aviso(): void
    {
        $logs = [];
        Log::listen(function ($e) use (&$logs) { $logs[] = $e; });
        $job = Mockery::mock(JobContrato::class);
        $job->shouldReceive('payload')->andReturn(['tenant_id' => 99999]);

        app(ExecutorDeJobsDeTenant::class)->failed(['commandName' => JobDeTeste::class], new Exception('x'), 'u', $job);

        $this->assertSame('warning', $logs[0]->level);
        $this->assertSame(['tenant_id' => 99999, 'job' => JobDeTeste::class, 'motivo' => 'inexistente'], $logs[0]->context);
    }
}
