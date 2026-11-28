<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Modules\Core\Comunicacao\Email\Dominio\ClienteDeDominiosIndisponivel;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Core\Comunicacao\Email\CanalEnvioEmail;
use Modules\Core\Comunicacao\Email\Dominio\ClienteDeDominios;
use Modules\Core\Comunicacao\Email\Dominio\ClienteIndisponivel;
use Modules\Core\Comunicacao\Email\Dominio\ClienteResend;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Comunicacao\Email\MailerDaEscola;
use Modules\Core\Comunicacao\Email\RemetenteTenant;
use Modules\Core\Models\DominioEmail;
use Modules\Permissao\Enums\Perfil;
use Tests\Fixtures\Comunicacao\NotificacaoDeTeste;
use Tests\TestCase;

class ConfiguracaoEmailDominioHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function admin()
    {
        return $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com'));
    }

    private function registar(string $dominio = 'escola.ao', array $extra = [])
    {
        return $this->post('/configuracao-email/dominio', $extra + ['dominio' => $dominio, 'remetente_nome' => 'Secretaria', 'remetente_local' => 'secretaria']);
    }

    public function test_registar_guarda_os_registos_dns_e_fica_por_verificar_e_inactivo(): void
    {
        $this->admin();

        $this->registar()->assertRedirect('/configuracao-email');

        $d = DominioEmail::sole();
        $this->assertSame('escola.ao', $d->dominio);
        $this->assertSame(EstadoConfiguracaoEmail::POR_VERIFICAR, $d->estado);
        $this->assertFalse($d->activo);
        $this->assertCount(2, $d->registos);
        $this->get('/configuracao-email')->assertInertia(fn (Assert $p) => $p
            ->where('dominioEmail.dominio', 'escola.ao')->where('dominioEmail.endereco', 'secretaria@escola.ao')->has('dominioEmail.registos', 2));
    }

    public function test_verificar_activa_e_a_escola_passa_a_enviar_do_seu_dominio_pelo_resend(): void
    {
        $this->admin();
        $this->registar();

        $this->post('/configuracao-email/dominio/verificar')->assertRedirect('/configuracao-email');

        $d = DominioEmail::sole();
        $this->assertSame(EstadoConfiguracaoEmail::VERIFICADO, $d->estado);
        $this->assertTrue($d->activo);
        $this->assertSame(CanalEnvioEmail::PROPRIO, app(MailerDaEscola::class)->canal());

        $mensagem = (new NotificacaoDeTeste)->toMail(new AnonymousNotifiable);
        $this->assertSame('secretaria@escola.ao', $mensagem->from[0]);
        $this->assertSame('Secretaria', $mensagem->from[1]);
        $this->assertSame('resend_dominios', $mensagem->mailer);
        $this->assertSame('secretaria@escola.ao', app(RemetenteTenant::class)->resolverDeDominio($d)->endereco);
    }

    public function test_dominio_com_falha_ou_pendente_nao_activa(): void
    {
        $this->admin();

        foreach (['falha.ao' => EstadoConfiguracaoEmail::COM_FALHA, 'pendente.ao' => EstadoConfiguracaoEmail::POR_VERIFICAR] as $dominio => $esperado) {
            $this->registar($dominio);
            $this->post('/configuracao-email/dominio/verificar');

            $d = DominioEmail::sole();
            $this->assertSame($esperado, $d->estado);
            $this->assertFalse($d->activo);
            $this->assertNull(app(MailerDaEscola::class)->dominio());
        }
    }

    public function test_o_pedido_nunca_consegue_marcar_o_dominio_como_verificado(): void
    {
        $this->admin();

        $this->registar('pendente.ao', ['estado' => 1, 'activo' => true, 'verificado_em' => now()->toDateTimeString()]);

        $d = DominioEmail::sole();
        $this->assertSame(EstadoConfiguracaoEmail::POR_VERIFICAR, $d->estado);
        $this->assertFalse($d->activo);
        $this->assertNull($d->verificado_em);
    }

    public function test_dominios_invalidos_ou_da_plataforma_sao_recusados(): void
    {
        $this->admin();
        config(['app.url' => 'https://mositec.ao', 'mail.from.address' => 'noreply@mail.mositec.ao']);

        foreach (['http://escola.ao', '192.168.0.1', 'sem-ponto', 'mositec.ao', 'escola.mositec.ao', 'mail.mositec.ao'] as $mau) {
            $this->registar($mau)->assertSessionHasErrors('dominio');
        }

        $this->assertSame(0, DominioEmail::query()->count());
    }

    public function test_sem_chave_em_producao_nada_se_regista_nem_activa(): void
    {
        $this->admin();
        $this->app->bind(ClienteDeDominios::class, ClienteIndisponivel::class);

        $this->registar()->assertSessionHasErrors('dominio');

        $this->assertSame(0, DominioEmail::query()->count());
    }

    public function test_remover_volta_a_central(): void
    {
        $this->admin();
        $this->registar();
        $this->post('/configuracao-email/dominio/verificar');

        $this->delete('/configuracao-email/dominio')->assertRedirect('/configuracao-email');

        $this->assertSame(0, DominioEmail::query()->count());
        $this->assertSame(CanalEnvioEmail::CENTRAL, app(MailerDaEscola::class)->canal());
    }

    public function test_sem_permissao_recebe_403(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'p@example.com'));

        $this->post('/configuracao-email/dominio', ['dominio' => 'escola.ao'])->assertForbidden();
        $this->post('/configuracao-email/dominio/verificar')->assertForbidden();
        $this->delete('/configuracao-email/dominio')->assertForbidden();
    }

    public function test_cliente_resend_regista_e_mapeia_estados_sem_expor_a_chave(): void
    {
        config(['mail.mailers.resend_dominios.key' => 're_chave_secreta']);
        Http::fake([
            'api.resend.com/domains' => Http::response(['id' => 'dom_1', 'records' => [['record' => 'SPF', 'type' => 'TXT', 'name' => 'send', 'value' => 'v=spf1']]]),
            'api.resend.com/domains/dom_1/verify' => Http::response(['id' => 'dom_1']),
            'api.resend.com/domains/dom_1' => Http::response(['status' => 'verified']),
        ]);
        $cliente = new ClienteResend;

        $registado = $cliente->registar('escola.ao');

        $this->assertSame('dom_1', $registado['id']);
        $this->assertSame([['tipo' => 'TXT', 'nome' => 'send', 'valor' => 'v=spf1']], $registado['registos']);
        $this->assertSame(EstadoConfiguracaoEmail::VERIFICADO, $cliente->verificar('dom_1'));

        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['message' => 'x re_chave_secreta'], 500)]);
        try {
            $cliente->registar('outra.ao');
            $this->fail('Devia falhar.');
        } catch (ClienteDeDominiosIndisponivel $e) {
            $this->assertStringNotContainsString('re_chave_secreta', $e->getMessage());
        }
    }

    public function test_cada_escola_so_ve_o_seu_dominio(): void
    {
        $this->admin();
        $this->registar();
        $outra = $this->criarTenant('MOSI-000002', 'Colégio B', 'colegiob.ao');

        $this->noTenant($outra, function () {
            $this->assertSame(0, DominioEmail::query()->count());
            $this->assertNull(app(MailerDaEscola::class)->dominio());
        });
    }
}
