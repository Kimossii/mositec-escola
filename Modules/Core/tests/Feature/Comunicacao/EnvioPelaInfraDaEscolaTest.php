<?php

namespace Modules\Core\Tests\Feature\Comunicacao;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Modules\Core\Comunicacao\Email\CanalEnvioEmail;
use Modules\Core\Comunicacao\Email\EmailDisponivel;
use Modules\Core\Comunicacao\Email\EncriptacaoSmtp;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Comunicacao\Email\MailerDaEscola;
use Modules\Core\Comunicacao\Email\RemetenteTenant;
use Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp;
use Modules\Core\Models\ConfiguracaoEmail;
use Modules\Core\Models\EnvioEmail;
use RuntimeException;
use Tests\Fixtures\Comunicacao\NotificacaoDeTeste;
use Tests\TestCase;

class EnvioPelaInfraDaEscolaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'mail.default' => 'array', 'mail.from.address' => 'noreply@plataforma.test', 'app.url' => 'http://localhost']);
        $this->app->forgetInstance('mail.manager');
        // Sem rede nos testes: o servidor «resolve» para um IP público.
        $this->app->bind(ValidadorDeServidorSmtp::class, fn () => new ValidadorDeServidorSmtp(fn () => ['142.250.1.1']));
    }

    private function config(array $extra = []): ConfiguracaoEmail
    {
        return ConfiguracaoEmail::create($extra + [
            'servidor' => 'smtp.exemplo.com',
            'porta' => 587,
            'encriptacao' => EncriptacaoSmtp::STARTTLS,
            'utilizador' => 'conta',
            'senha' => 'segredo',
            'remetente_nome' => 'Secretaria',
            'remetente_endereco' => 'secretaria@exemplo.com',
            'responder_para' => 'direccao@exemplo.com',
            'estado' => EstadoConfiguracaoEmail::VERIFICADO,
            'activo' => true,
        ]);
    }

    private function canalDeEnvio(?string $falhaCom = null): void
    {
        $this->app->bind(MailChannel::class, fn () => new class($falhaCom) extends MailChannel
        {
            public function __construct(private ?string $falhaCom) {}

            public function send($notifiable, Notification $notification)
            {
                if ($this->falhaCom !== null) {
                    throw new RuntimeException($this->falhaCom);
                }

                return null;
            }
        });
    }

    public function test_configDe_traduz_todos_os_campos(): void
    {
        $c = $this->config(['encriptacao' => EncriptacaoSmtp::SSL, 'porta' => 465, 'dominio_ehlo' => 'escola.exemplo.com', 'tempo_limite' => 20, 'validar_certificado' => false]);
        $cfg = MailerDaEscola::configDe($c);

        $this->assertSame('smtp', $cfg['transport']);
        $this->assertSame('smtp.exemplo.com', $cfg['host']);
        $this->assertSame(465, $cfg['port']);
        $this->assertSame('smtps', $cfg['scheme']);
        $this->assertSame('conta', $cfg['username']);
        $this->assertSame('segredo', $cfg['password']);
        $this->assertSame('escola.exemplo.com', $cfg['local_domain']);
        $this->assertSame(20, $cfg['timeout']);
        $this->assertFalse($cfg['verify_peer']);

        $starttls = MailerDaEscola::configDe($this->config2(EncriptacaoSmtp::STARTTLS));
        $this->assertSame('smtp', $starttls['scheme']);
        $this->assertTrue($starttls['verify_peer']);
        $this->assertTrue($starttls['require_tls']);

        $nenhuma = MailerDaEscola::configDe($this->config2(EncriptacaoSmtp::NENHUMA));
        $this->assertSame('smtp', $nenhuma['scheme']);
        $this->assertFalse($nenhuma['auto_tls']);
    }

    private function config2(EncriptacaoSmtp $e): ConfiguracaoEmail
    {
        return new ConfiguracaoEmail(['servidor' => 's.exemplo.com', 'porta' => 25, 'encriptacao' => $e, 'remetente_endereco' => 'a@exemplo.com', 'validar_certificado' => true, 'tempo_limite' => 15]);
    }

    public function test_configDe_sem_utilizador_nem_senha_nao_leva_credenciais(): void
    {
        $cfg = MailerDaEscola::configDe($this->config(['utilizador' => null, 'senha' => null]));

        $this->assertNull($cfg['username']);
        $this->assertNull($cfg['password']);
    }

    public function test_sem_configuracao_ou_nao_verificada_usa_a_central(): void
    {
        $mailer = app(MailerDaEscola::class);
        $this->assertNull($mailer->nome());
        $this->assertSame(CanalEnvioEmail::CENTRAL, $mailer->canal());

        foreach ([['estado' => EstadoConfiguracaoEmail::POR_VERIFICAR], ['estado' => EstadoConfiguracaoEmail::COM_FALHA], ['activo' => false]] as $caso) {
            ConfiguracaoEmail::query()->delete();
            $this->config($caso);

            $this->assertNull(app(MailerDaEscola::class)->nome());
            $mensagem = (new NotificacaoDeTeste)->toMail(new AnonymousNotifiable);
            $this->assertNull($mensagem->mailer);
            $this->assertSame('noreply@plataforma.test', $mensagem->from[0]);
        }
    }

    public function test_verificada_e_activa_usa_o_mailer_da_escola_e_o_remetente_dela(): void
    {
        $this->config();

        $mensagem = (new NotificacaoDeTeste)->toMail(new AnonymousNotifiable);

        $this->assertSame('escola', $mensagem->mailer);
        $this->assertSame(['secretaria@exemplo.com', 'Secretaria'], $mensagem->from);
        $this->assertSame([['direccao@exemplo.com', null]], $mensagem->replyTo);
        $this->assertSame('smtp.exemplo.com', config('mail.mailers.escola.host'));
    }

    public function test_remetente_da_escola_sem_nome_usa_o_nome_da_escola(): void
    {
        $this->config(['remetente_nome' => null, 'responder_para' => null]);

        $remetente = app(RemetenteTenant::class)->resolver();

        $this->assertSame('Escola de Teste', $remetente->nome);
        $this->assertSame('secretaria@exemplo.com', $remetente->endereco);
    }

    public function test_envio_registado_com_canal_proprio(): void
    {
        $this->config();
        $this->canalDeEnvio();

        (new AnonymousNotifiable)->route('mail', 'pai@example.com')->notify(new NotificacaoDeTeste);

        $envio = EnvioEmail::query()->sole();
        $this->assertSame(CanalEnvioEmail::PROPRIO, $envio->canal);
        $this->assertSame('Infra própria da escola', $envio->canal_descricao);
        $this->assertSame(EstadoEnvioEmail::ENVIADO, $envio->estado);
    }

    public function test_falha_pela_infra_propria_regista_e_relanca(): void
    {
        $this->config();
        $this->canalDeEnvio('Connection refused');

        try {
            (new AnonymousNotifiable)->route('mail', 'pai@example.com')->notify(new NotificacaoDeTeste);
            $this->fail('A excepção devia continuar a subir.');
        } catch (RuntimeException) {
        }

        $envio = EnvioEmail::query()->sole();
        $this->assertSame(EstadoEnvioEmail::FALHOU, $envio->estado);
        $this->assertSame(CanalEnvioEmail::PROPRIO, $envio->canal);
        $this->assertStringNotContainsString('segredo', (string) $envio->erro);
    }

    public function test_EmailDisponivel_conta_a_infra_verificada_mesmo_com_mailer_log(): void
    {
        config(['mail.default' => 'log']);
        $this->assertFalse(app(EmailDisponivel::class)->disponivel());

        $this->config(['activo' => false]);
        $this->assertFalse(app(EmailDisponivel::class)->disponivel());

        ConfiguracaoEmail::query()->update(['activo' => true]);
        $this->assertTrue(app(EmailDisponivel::class)->disponivel());
    }

    public function test_servidor_que_passa_a_apontar_a_rede_interna_depois_de_verificado_cai_na_central(): void
    {
        $this->config();
        $this->assertSame('escola', app(MailerDaEscola::class)->nome());

        $this->app->bind(ValidadorDeServidorSmtp::class, fn () => new ValidadorDeServidorSmtp(fn () => ['10.0.0.5']));

        $this->assertNull(app(MailerDaEscola::class)->nome());
        $this->assertSame(CanalEnvioEmail::CENTRAL, app(MailerDaEscola::class)->canal());
        $this->assertNull((new NotificacaoDeTeste)->toMail(new AnonymousNotifiable)->mailer);
    }

    public function test_bloqueada_pela_plataforma_usa_a_central_mesmo_verificada_e_activa(): void
    {
        $this->config();
        ConfiguracaoEmail::query()->update(['bloqueada_pela_plataforma' => true]);

        $this->assertNull(app(MailerDaEscola::class)->nome());
        $this->assertSame(CanalEnvioEmail::CENTRAL, app(MailerDaEscola::class)->canal());
    }

    public function test_a_configuracao_e_lida_uma_so_vez_por_mensagem(): void
    {
        $this->config();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        (new NotificacaoDeTeste)->toMail(new AnonymousNotifiable);
        $leituras = array_filter(\Illuminate\Support\Facades\DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'configuracoes_email'));
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(1, $leituras);
    }

    public function test_o_canal_registado_e_o_do_mailer_usado_mesmo_se_a_escola_voltar_a_central_a_meio(): void
    {
        $this->config();
        $notificacao = new NotificacaoDeTeste;
        $mensagem = $notificacao->toMail(new AnonymousNotifiable);
        $this->assertSame('escola', $mensagem->mailer);

        // A Plataforma força o regresso depois de a mensagem estar montada e antes de o envio ficar registado.
        ConfiguracaoEmail::query()->update(['activo' => false]);
        app(\Modules\Core\Comunicacao\Email\RegistoDeEnvios::class)->enviado($notificacao, (new AnonymousNotifiable)->route('mail', 'pai@example.com'));

        $this->assertSame(CanalEnvioEmail::PROPRIO, EnvioEmail::query()->sole()->canal);
    }

    public function test_o_erro_registado_nao_leva_o_utilizador_nem_a_senha_do_servidor_da_escola(): void
    {
        $this->config(['utilizador' => 'AKIAUTILIZADOR1', 'senha' => 'senha-muito-secreta']);
        $this->canalDeEnvio('Failed to authenticate on SMTP server with username "AKIAUTILIZADOR1" and password "senha-muito-secreta".');

        try {
            (new AnonymousNotifiable)->route('mail', 'pai@example.com')->notify(new NotificacaoDeTeste);
        } catch (RuntimeException) {
        }

        $erro = (string) EnvioEmail::query()->sole()->erro;
        $this->assertStringNotContainsString('AKIAUTILIZADOR1', $erro);
        $this->assertStringNotContainsString('senha-muito-secreta', $erro);
        $this->assertStringContainsString('Failed to authenticate', $erro);
    }
}
