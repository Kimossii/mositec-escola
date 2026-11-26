<?php

namespace Modules\Core\Tests\Feature\Comunicacao;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use LogicException;
use Modules\Core\Licenciamento\Contracts\ModulosDisponiveis;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\RemetenteTenant;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Models\Tenant;
use Tests\Fixtures\Comunicacao\NotificacaoDeTeste;
use Tests\TestCase;

class EmailDeTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'noreply@plataforma.test', 'app.url' => 'http://localhost', 'seguranca.forcar_https' => false]);
        $this->outro = $this->criarTenant('MOSI-000002', 'Colégio B', 'colegiob.ao');
    }

    public function test_o_remetente_leva_o_nome_da_escola_e_o_endereco_central(): void
    {
        $remetente = app(RemetenteTenant::class)->resolver();

        $this->assertSame('Escola de Teste', $remetente->nome);
        $this->assertSame('noreply@plataforma.test', $remetente->endereco);
        $this->assertNull($remetente->replyTo);

        $this->noTenant($this->outro, function () {
            $this->assertSame('Colégio B', app(RemetenteTenant::class)->resolver()->nome);
        });
    }

    public function test_as_ligacoes_usam_o_dominio_principal_de_cada_escola(): void
    {
        $this->assertSame('http://localhost/login', app(RemetenteTenant::class)->url('/login'));

        $this->noTenant($this->outro, function () {
            $this->assertSame('http://colegiob.ao/login', app(RemetenteTenant::class)->url('login'));
        });
    }

    public function test_forcar_https_impoe_o_esquema_seguro(): void
    {
        config(['seguranca.forcar_https' => true]);

        $this->assertSame('https://localhost/', app(RemetenteTenant::class)->url());
    }

    public function test_tenant_sem_dominio_principal_nao_gera_ligacoes(): void
    {
        $this->outro->dominios()->delete();

        $this->expectException(LogicException::class);

        $this->noTenant($this->outro, fn () => app(RemetenteTenant::class)->url('/login'));
    }

    public function test_a_notificacao_sai_com_o_from_e_o_link_da_escola_certa(): void
    {
        $mensagem = $this->noTenant($this->outro, fn () => (new NotificacaoDeTeste)->toMail(new AnonymousNotifiable));

        $this->assertSame(['noreply@plataforma.test', 'Colégio B'], $mensagem->from);
        $this->assertSame('http://colegiob.ao/login', $mensagem->actionUrl);
    }

    public function test_despachar_sem_contexto_de_tenant_falha(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        (new AnonymousNotifiable)->route('mail', 'alguem@example.com')->notify(new NotificacaoDeTeste);
    }

    public function test_o_limite_por_hora_e_por_tenant_e_por_categoria(): void
    {
        config(['comunicacao.limites_por_hora' => ['essencial' => 2, 'opcional' => 1]]);
        $notificacao = new NotificacaoDeTeste;
        $opcional = new NotificacaoDeTeste(CategoriaEmail::OPCIONAL);
        $destino = new AnonymousNotifiable;

        $this->assertTrue($notificacao->shouldSend($destino, 'mail'));
        $this->assertTrue($notificacao->shouldSend($destino, 'mail'));
        $this->assertFalse($notificacao->shouldSend($destino, 'mail'));

        // Os opcionais têm contador próprio: os essenciais esgotados não os bloqueiam.
        $this->assertTrue($opcional->shouldSend($destino, 'mail'));
        $this->assertFalse($opcional->shouldSend($destino, 'mail'));

        // Outra escola tem o seu próprio contador.
        $this->noTenant($this->outro, fn () => $this->assertTrue($notificacao->shouldSend($destino, 'mail')));
    }

    public function test_o_reply_to_e_o_email_institucional_da_escola(): void
    {
        Estabelecimento::query()->update(['email' => 'secretaria@escola-teste.ao']);
        $this->noTenant($this->outro, fn () => Estabelecimento::query()->update(['email' => 'email-invalido']));

        $this->assertSame('secretaria@escola-teste.ao', app(RemetenteTenant::class)->resolver()->replyTo);
        $this->noTenant($this->outro, fn () => $this->assertNull(app(RemetenteTenant::class)->resolver()->replyTo));

        $mensagem = (new NotificacaoDeTeste)->toMail(new AnonymousNotifiable);
        $this->assertSame([['secretaria@escola-teste.ao', null]], $mensagem->replyTo);
    }

    public function test_opcionais_exigem_o_modulo_notificacoes_e_essenciais_nao(): void
    {
        $this->app->instance(ModulosDisponiveis::class, new class implements ModulosDisponiveis
        {
            public function disponivel(string $alias): bool
            {
                return false;
            }
        });
        $destino = new AnonymousNotifiable;

        $this->assertFalse((new NotificacaoDeTeste(CategoriaEmail::OPCIONAL))->shouldSend($destino, 'mail'));
        $this->assertTrue((new NotificacaoDeTeste(CategoriaEmail::ESSENCIAL))->shouldSend($destino, 'mail'));

        $this->app->instance(ModulosDisponiveis::class, new class implements ModulosDisponiveis
        {
            public function disponivel(string $alias): bool
            {
                return $alias === 'notificacoes';
            }
        });

        $this->assertTrue((new NotificacaoDeTeste(CategoriaEmail::OPCIONAL))->shouldSend($destino, 'mail'));
    }

    public function test_a_assinatura_leva_os_contactos_que_a_escola_tiver(): void
    {
        $this->assertSame("Cumprimentos,\n\nEscola de Teste", app(RemetenteTenant::class)->assinatura());

        Estabelecimento::query()->update(['telefone' => '+244 923 000 000', 'email' => 'secretaria@escola-teste.ao']);
        $this->assertSame(
            "Cumprimentos,\n\nEscola de Teste\n\nPara contactar a escola: Telefone: +244 923 000 000 · Email: secretaria@escola-teste.ao",
            app(RemetenteTenant::class)->assinatura(),
        );

        Estabelecimento::query()->update(['telefone' => null, 'email' => 'invalido']);
        $this->assertSame("Cumprimentos,\n\nEscola de Teste", app(RemetenteTenant::class)->assinatura());

        $mensagem = (new NotificacaoDeTeste)->toMail(new AnonymousNotifiable);
        $this->assertSame("Cumprimentos,\n\nEscola de Teste", $mensagem->salutation);
    }

    public function test_em_instalacao_unica_os_links_usam_o_app_url_inteiro_com_a_porta(): void
    {
        config(['tenancy.modo' => 'unico', 'app.url' => 'http://192.168.1.10:8000']);

        $this->assertSame('http://192.168.1.10:8000/login', app(RemetenteTenant::class)->url('/login'));

        // No modo normal continua a ser o domínio principal do tenant.
        config(['tenancy.modo' => 'dominio']);
        $this->assertSame('http://localhost/login', app(RemetenteTenant::class)->url('/login'));
    }

    public function test_um_destinatario_avulso_sem_conta_recebe_o_email_pela_fila(): void
    {
        // As notificações não dependem de o destinatário ter conta na plataforma: basta um endereço.
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        $this->app->forgetInstance('mail.manager');

        (new AnonymousNotifiable)->route('mail', 'pai-sem-conta@example.com')->notify(new NotificacaoDeTeste);

        $enviadas = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $enviadas);
        $email = $enviadas->first()->getOriginalMessage();
        $this->assertSame('pai-sem-conta@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Escola de Teste', $email->getFrom()[0]->getName());
        $this->assertStringContainsString('http://localhost/login', $email->getHtmlBody());
        $this->assertStringContainsString('Escola de Teste', $email->getHtmlBody());
    }
}
