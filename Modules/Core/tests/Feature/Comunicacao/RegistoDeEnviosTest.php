<?php

namespace Modules\Core\Tests\Feature\Comunicacao;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Comunicacao\Email\PodarEnviosEmailAction;
use Modules\Core\Comunicacao\Email\RegistoDeEnvios;
use Modules\Core\Licenciamento\Contracts\ModulosDisponiveis;
use Modules\Core\Models\EnvioEmail;
use Modules\Core\Tenancy\TenantContext;
use Modules\Usuario\Models\User;
use RuntimeException;
use Tests\Fixtures\Comunicacao\NotificacaoDeTeste;
use Tests\TestCase;

class RegistoDeEnviosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'mail.default' => 'array', 'mail.from.address' => 'noreply@plataforma.test']);
        $this->app->forgetInstance('mail.manager');
    }

    private function falharOEnvioCom(string $mensagem): void
    {
        $this->app->bind(MailChannel::class, fn () => new class($mensagem) extends MailChannel
        {
            public function __construct(private string $mensagem) {}

            public function send($notifiable, Notification $notification)
            {
                throw new RuntimeException($this->mensagem);
            }
        });
    }

    public function test_um_envio_com_sucesso_fica_registado_com_o_destinatario_mascarado(): void
    {
        $pai = User::create(['name' => 'Pai', 'email' => 'pai.silva@example.com', 'password' => Hash::make('x')]);

        $pai->notify(new NotificacaoDeTeste);

        $envio = EnvioEmail::query()->sole();
        $this->assertSame(EstadoEnvioEmail::ENVIADO, $envio->estado);
        $this->assertSame('Enviado', $envio->estado_descricao);
        $this->assertSame('Central', $envio->canal_descricao);
        $this->assertSame(CategoriaEmail::ESSENCIAL, $envio->categoria);
        $this->assertSame('NotificacaoDeTeste', $envio->notificacao);
        $this->assertSame($pai->id, $envio->destinatario_user_id);
        $this->assertSame('p***@example.com', $envio->destinatario_mascarado);
        $this->assertNull($envio->erro);
        $this->assertStringNotContainsString('pai.silva', json_encode($envio->getAttributes()));
    }

    public function test_um_destinatario_avulso_fica_registado_sem_utilizador(): void
    {
        (new AnonymousNotifiable)->route('mail', 'encarregado@example.com')->notify(new NotificacaoDeTeste);

        $envio = EnvioEmail::query()->sole();
        $this->assertNull($envio->destinatario_user_id);
        $this->assertSame('e***@example.com', $envio->destinatario_mascarado);
    }

    public function test_uma_falha_de_envio_fica_registada_sem_segredos_e_a_excepcao_continua(): void
    {
        $this->falharOEnvioCom('Connection refused for pai@example.com via smtp://utilizador:segredo123@smtp.escola.ao:587');

        try {
            (new AnonymousNotifiable)->route('mail', 'pai@example.com')->notify(new NotificacaoDeTeste);
            $this->fail('A falha do provedor devia continuar a subir (a fila repete e marca como falhada).');
        } catch (RuntimeException) {
        }

        $envio = EnvioEmail::query()->sole();
        $this->assertSame(EstadoEnvioEmail::FALHOU, $envio->estado);
        $this->assertStringContainsString('RuntimeException', $envio->erro);
        $this->assertStringNotContainsString('segredo123', $envio->erro);
        $this->assertStringNotContainsString('utilizador:', $envio->erro);
        $this->assertStringNotContainsString('pai@example.com', $envio->erro);
        $this->assertLessThanOrEqual(500, strlen($envio->erro));
    }

    public function test_os_descartes_por_limite_e_por_contrato_ficam_registados_e_nada_e_enviado(): void
    {
        $destino = (new AnonymousNotifiable)->route('mail', 'pai@example.com');

        config(['comunicacao.limites_por_hora.essencial' => 1]);
        $destino->notify(new NotificacaoDeTeste);
        $destino->notify(new NotificacaoDeTeste);

        $this->app->instance(ModulosDisponiveis::class, new class implements ModulosDisponiveis
        {
            public function disponivel(string $alias): bool
            {
                return false;
            }
        });
        $destino->notify(new NotificacaoDeTeste(CategoriaEmail::OPCIONAL));

        $this->assertSame(
            [EstadoEnvioEmail::ENVIADO, EstadoEnvioEmail::DESCARTADO_LIMITE, EstadoEnvioEmail::DESCARTADO_CONTRATO],
            EnvioEmail::query()->orderBy('id')->get()->map->estado->all(),
        );
        $this->assertCount(1, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_cada_escola_so_ve_os_seus_registos(): void
    {
        $outra = $this->criarTenant('MOSI-000002', 'Colégio B', 'colegiob.ao');
        (new AnonymousNotifiable)->route('mail', 'a@example.com')->notify(new NotificacaoDeTeste);
        $this->noTenant($outra, fn () => (new AnonymousNotifiable)->route('mail', 'b@example.com')->notify(new NotificacaoDeTeste));

        $this->assertSame('a***@example.com', EnvioEmail::query()->sole()->destinatario_mascarado);
        $this->noTenant($outra, fn () => $this->assertSame('b***@example.com', EnvioEmail::query()->sole()->destinatario_mascarado));
    }

    public function test_sem_contexto_de_tenant_o_registo_nao_lanca_nem_grava(): void
    {
        app(TenantContext::class)->limpar();

        app(RegistoDeEnvios::class)->enviado(new NotificacaoDeTeste, (new AnonymousNotifiable)->route('mail', 'pai@example.com'));

        $this->assertSame(0, EnvioEmail::withoutGlobalScopes()->count());
    }

    public function test_a_poda_apaga_so_o_historico_antigo_da_escola(): void
    {
        $destino = (new AnonymousNotifiable)->route('mail', 'pai@example.com');
        $destino->notify(new NotificacaoDeTeste);
        $destino->notify(new NotificacaoDeTeste);
        EnvioEmail::query()->orderBy('id')->first()->forceFill(['created_at' => now()->subDays(200)])->save();

        $apagados = app(PodarEnviosEmailAction::class)->executar(180);

        $this->assertSame(1, $apagados);
        $this->assertSame(1, EnvioEmail::query()->count());
    }

    public function test_mascarar_e_resumir_o_erro(): void
    {
        $this->assertSame('p***@gmail.com', RegistoDeEnvios::mascarar('pai@gmail.com'));
        $this->assertNull(RegistoDeEnvios::mascarar('sem-arroba'));
        $this->assertNull(RegistoDeEnvios::mascarar(null));

        $longo = RegistoDeEnvios::resumirErro(new RuntimeException(str_repeat('x', 2000)));
        $this->assertLessThanOrEqual(500, mb_strlen($longo));
    }
}
