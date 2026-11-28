<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\Email\RegistarDominioDeEmailAction;
use Modules\Core\Actions\Email\RemoverDominioDeEmailAction;
use Modules\Core\Actions\Email\VerificarDominioDeEmailAction;
use Modules\Core\Comunicacao\Email\CanalEnvioEmail;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\EncriptacaoSmtp;
use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Comunicacao\Email\MailerDaEscola;
use Modules\Core\Models\DominioEmail;
use Modules\Core\Models\EnvioEmail;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Models\ConfiguracaoEmail;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Tests\TestCase;

/** Cartão «Escolas com infra própria» do painel e o botão «Forçar regresso à central»: tudo sem contexto de escola. */
class InfraDeEmailNaPlataformaTest extends TestCase
{
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private string $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        config(['tenancy.hosts_centrais' => [self::CENTRAL], 'session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->sessao = $this->entrarNoPainel($this->superAdmin());
    }

    private function configurar($escola, array $extra = []): ConfiguracaoEmail
    {
        return app(TenantContext::class)->executarComo($escola->paraTenantAtual(), fn () => ConfiguracaoEmail::create($extra + [
            'servidor' => 'smtp.secreto-da-escola.com',
            'porta' => 587,
            'encriptacao' => EncriptacaoSmtp::STARTTLS,
            'utilizador' => 'utilizador-secreto',
            'senha' => 'senha-secreta-123',
            'remetente_endereco' => 'secretaria@escola.ao',
            'estado' => EstadoConfiguracaoEmail::VERIFICADO,
            'activo' => true,
        ]));
    }

    private function activa($escola): ?bool
    {
        return ConfiguracaoEmail::query()->withoutGlobalScopes()->where('tenant_id', $escola->id)->value('activo');
    }

    private function bloqueada($escola): bool
    {
        return (bool) ConfiguracaoEmail::query()->withoutGlobalScopes()->where('tenant_id', $escola->id)->value('bloqueada_pela_plataforma');
    }

    public function test_o_painel_lista_so_escolas_com_configuracao_sem_dados_do_servidor(): void
    {
        $a = $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');
        $this->escolaDeGestao('MOSI-900002', 'Escola B', 'b.test');
        $c = $this->escolaDeGestao('MOSI-900003', 'Escola C', 'c.test');
        $this->configurar($a);
        $this->configurar($c, ['estado' => EstadoConfiguracaoEmail::COM_FALHA, 'activo' => false, 'ultimo_erro' => 'Connection refused']);

        $resposta = $this->noPainel('GET', '/plataforma', $this->sessao, cabecalhos: $this->cabecalhosInertia());

        $infra = collect($resposta->json('props.emailInfra'));
        $this->assertSame(['MOSI-900001', 'MOSI-900003'], $infra->pluck('escola')->sort()->values()->all());
        $this->assertSame('Connection refused', $infra->firstWhere('escola', 'MOSI-900003')['ultimo_erro']);
        $this->assertTrue($infra->firstWhere('escola', 'MOSI-900001')['activo']);
        $this->assertSame('Escola A', $infra->firstWhere('escola', 'MOSI-900001')['escola_nome']);

        $corpo = $resposta->getContent();
        foreach (['smtp.secreto-da-escola.com', 'utilizador-secreto', 'senha-secreta-123', 'secretaria@escola.ao'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $corpo);
        }
    }

    public function test_forcar_regresso_desactiva_so_a_escola_indicada_e_audita(): void
    {
        $a = $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');
        $b = $this->escolaDeGestao('MOSI-900002', 'Escola B', 'b.test');
        $this->configurar($a);
        $this->configurar($b);

        $this->noPainel('POST', '/plataforma/escolas/MOSI-900001/email/regresso-central', $this->sessao)
            ->assertRedirect($this->urlCentral('/plataforma/escolas/MOSI-900001'));

        $this->assertFalse((bool) $this->activa($a));
        $this->assertTrue((bool) $this->activa($b));
        $this->assertTrue($this->bloqueada($a));
        $this->assertFalse($this->bloqueada($b));

        $registo = RegistoDeAuditoria::where('accao', 'email.regresso_central_forcado')->sole();
        $this->assertSame('MOSI-900001', $registo->codigo_tenant);
    }

    public function test_escola_sem_configuracao_da_404(): void
    {
        $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');

        $this->noPainel('POST', '/plataforma/escolas/MOSI-900001/email/regresso-central', $this->sessao)->assertNotFound();
        $this->assertSame(0, RegistoDeAuditoria::where('accao', 'email.regresso_central_forcado')->count());
    }

    public function test_sem_sessao_vai_para_o_login_e_nada_muda(): void
    {
        $a = $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');
        $this->configurar($a);

        $this->pedido('POST', $this->urlCentral('/plataforma/escolas/MOSI-900001/email/regresso-central'))
            ->assertRedirect($this->urlCentral('/plataforma/login'));

        $this->assertTrue((bool) $this->activa($a));
    }

    public function test_o_detalhe_da_escola_diz_se_tem_infra_propria_activa(): void
    {
        $a = $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');
        $this->escolaDeGestao('MOSI-900002', 'Escola B', 'b.test');
        $this->configurar($a);

        $com = $this->noPainel('GET', '/plataforma/escolas/MOSI-900001', $this->sessao, cabecalhos: $this->cabecalhosInertia());
        $sem = $this->noPainel('GET', '/plataforma/escolas/MOSI-900002', $this->sessao, cabecalhos: $this->cabecalhosInertia());

        $this->assertTrue($com->json('props.escola.email_proprio_activo'));
        $this->assertFalse($sem->json('props.escola.email_proprio_activo'));
    }

    public function test_levantar_o_bloqueio_nao_reactiva_mas_deixa_a_escola_testar_de_novo(): void
    {
        $a = $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');
        $this->configurar($a);
        $this->noPainel('POST', '/plataforma/escolas/MOSI-900001/email/regresso-central', $this->sessao);

        $this->noPainel('POST', '/plataforma/escolas/MOSI-900001/email/levantar-bloqueio', $this->sessao)
            ->assertRedirect($this->urlCentral('/plataforma/escolas/MOSI-900001'));

        $this->assertFalse($this->bloqueada($a));
        $this->assertFalse((bool) $this->activa($a));
        $this->assertSame(1, RegistoDeAuditoria::where('accao', 'email.bloqueio_levantado')->count());
    }

    public function test_o_detalhe_e_o_painel_mostram_o_bloqueio(): void
    {
        $a = $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');
        $this->configurar($a);
        $this->noPainel('POST', '/plataforma/escolas/MOSI-900001/email/regresso-central', $this->sessao);

        $detalhe = $this->noPainel('GET', '/plataforma/escolas/MOSI-900001', $this->sessao, cabecalhos: $this->cabecalhosInertia());
        $painel = $this->noPainel('GET', '/plataforma', $this->sessao, cabecalhos: $this->cabecalhosInertia());

        $this->assertTrue($detalhe->json('props.escola.email_bloqueado'));
        $this->assertTrue($painel->json('props.emailInfra.0.bloqueada'));
    }

    private function dominio($escola): DominioEmail
    {
        return app(TenantContext::class)->executarComo($escola->paraTenantAtual(), fn () => DominioEmail::create([
            'dominio' => 'escola.ao', 'id_externo' => 'simulado:escola.ao', 'remetente_local' => 'secretaria',
            'estado' => EstadoConfiguracaoEmail::VERIFICADO, 'activo' => true,
        ]));
    }

    public function test_forcar_regresso_bloqueia_o_dominio_e_a_escola_nao_o_consegue_contornar(): void
    {
        $a = $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');
        $dominio = $this->dominio($a);

        $this->noPainel('POST', '/plataforma/escolas/MOSI-900001/email/regresso-central', $this->sessao)->assertRedirect();

        $guardado = DominioEmail::query()->withoutGlobalScopes()->find($dominio->id);
        $this->assertFalse($guardado->activo);
        $this->assertTrue($guardado->bloqueada_pela_plataforma);

        app(TenantContext::class)->executarComo($a->paraTenantAtual(), function () use ($guardado) {
            $this->assertNull(app(MailerDaEscola::class)->dominio());
            foreach ([
                fn () => app(VerificarDominioDeEmailAction::class)->executar($guardado),
                fn () => app(RemoverDominioDeEmailAction::class)->executar($guardado),
                fn () => app(RegistarDominioDeEmailAction::class)->executar('outro.ao', null),
            ] as $tentativa) {
                try {
                    $tentativa();
                    $this->fail('A escola não devia conseguir contornar o bloqueio.');
                } catch (ValidationException) {
                    $this->addToAssertionCount(1);
                }
            }
        });

        $this->noPainel('POST', '/plataforma/escolas/MOSI-900001/email/levantar-bloqueio', $this->sessao)->assertRedirect();
        $depois = DominioEmail::query()->withoutGlobalScopes()->find($dominio->id);
        $this->assertFalse($depois->bloqueada_pela_plataforma);
        $this->assertFalse($depois->activo); // levantar não reactiva
    }

    public function test_o_painel_mostra_o_dominio_e_o_volume_das_ultimas_24_horas(): void
    {
        $a = $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');
        $b = $this->escolaDeGestao('MOSI-900002', 'Escola B', 'b.test');
        $this->dominio($a);
        $envio = fn ($escola, CanalEnvioEmail $canal, int $vezes) => app(TenantContext::class)->executarComo($escola->paraTenantAtual(), function () use ($canal, $vezes) {
            for ($i = 0; $i < $vezes; $i++) {
                EnvioEmail::create(['notificacao' => 'X', 'categoria' => CategoriaEmail::OPCIONAL, 'estado' => EstadoEnvioEmail::ENVIADO, 'canal' => $canal]);
            }
        });
        $envio($a, CanalEnvioEmail::PROPRIO, 3);
        $envio($b, CanalEnvioEmail::CENTRAL, 5);

        $props = $this->noPainel('GET', '/plataforma', $this->sessao, cabecalhos: $this->cabecalhosInertia());

        $this->assertSame(['MOSI-900002', 'MOSI-900001'], collect($props->json('props.emailVolume'))->pluck('escola')->all());
        $this->assertSame(5, $props->json('props.emailVolume.0.enviados_24h'));
        $this->assertTrue($props->json('props.emailVolume.1.canal_proprio'));
        $infra = collect($props->json('props.emailInfra'))->firstWhere('escola', 'MOSI-900001');
        $this->assertSame('Domínio', $infra['tipo']);
        $this->assertStringNotContainsString('secretaria@escola.ao', $props->getContent());
    }
}
