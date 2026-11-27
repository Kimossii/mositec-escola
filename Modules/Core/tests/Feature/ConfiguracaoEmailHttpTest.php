<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Core\Actions\Email\TestarConfiguracaoEmailAction;
use Modules\Core\Comunicacao\Email\EncriptacaoSmtp;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp;
use Modules\Core\Models\ConfiguracaoEmail;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class ConfiguracaoEmailHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('teste-email:'.app(\Modules\Core\Tenancy\TenantContext::class)->id());
        $this->app->bind(ValidadorDeServidorSmtp::class, fn () => new ValidadorDeServidorSmtp(fn () => ['142.250.1.1']));
        $this->app->bind(TestarConfiguracaoEmailAction::class, fn () => new TestarConfiguracaoEmailAction(fn () => null));
    }

    private function admin()
    {
        return $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com'));
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'servidor' => 'smtp.exemplo.com', 'porta' => 587, 'encriptacao' => 1, 'utilizador' => 'conta', 'senha' => 'segredo-limpo-123',
            'validar_certificado' => true, 'dominio_ehlo' => null, 'tempo_limite' => 15,
            'remetente_nome' => 'Secretaria', 'remetente_endereco' => 'secretaria@exemplo.com', 'responder_para' => null,
        ];
    }

    private function existente(array $extra = []): ConfiguracaoEmail
    {
        return ConfiguracaoEmail::create($extra + $this->payload(['encriptacao' => EncriptacaoSmtp::STARTTLS]));
    }

    public function test_admin_ve_a_pagina_sem_nunca_receber_a_senha(): void
    {
        $this->admin();
        $this->get('/configuracao-email')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Core/ConfiguracaoEmail/Index')->where('configuracao', null));

        $this->existente();
        $resposta = $this->get('/configuracao-email');
        $resposta->assertInertia(fn (Assert $p) => $p
            ->where('configuracao.servidor', 'smtp.exemplo.com')
            ->where('configuracao.tem_senha', true)
            ->where('configuracao.estado', 0)
            ->missing('configuracao.senha'));
        $this->assertStringNotContainsString('segredo-limpo-123', $resposta->getContent());

        ConfiguracaoEmail::query()->update(['senha' => null, 'utilizador' => null]);
        $this->get('/configuracao-email')->assertInertia(fn (Assert $p) => $p->where('configuracao.tem_senha', false));
    }

    public function test_funcionario_e_professor_recebem_403(): void
    {
        foreach ([[Perfil::FUNCIONARIO, 'f@example.com'], [Perfil::PROFESSOR, 'p@example.com']] as [$perfil, $email]) {
            $this->actingAs($this->criarUtilizadorComPerfil($perfil, $email));
            $this->get('/configuracao-email')->assertForbidden();
            $this->put('/configuracao-email', $this->payload())->assertForbidden();
            $this->post('/configuracao-email/testar', ['destino' => 'a@b.pt'])->assertForbidden();
            $this->post('/configuracao-email/desactivar')->assertForbidden();
        }
    }

    public function test_put_valido_grava_e_fica_por_verificar(): void
    {
        $this->admin();

        $this->put('/configuracao-email', $this->payload())->assertRedirect('/configuracao-email');

        $c = ConfiguracaoEmail::sole();
        $this->assertSame(EstadoConfiguracaoEmail::POR_VERIFICAR, $c->estado);
        $this->assertFalse($c->activo);
        $this->assertSame('segredo-limpo-123', $c->senha);
    }

    public function test_put_com_porta_80_ou_campos_em_falta_da_erro_de_validacao(): void
    {
        $this->admin();

        $this->put('/configuracao-email', $this->payload(['porta' => 80]))->assertSessionHasErrors('porta');
        $this->put('/configuracao-email', $this->payload(['tempo_limite' => 60]))->assertSessionHasErrors('tempo_limite');
        $this->put('/configuracao-email', $this->payload(['remetente_endereco' => 'nao-e-email']))->assertSessionHasErrors('remetente_endereco');
        $this->put('/configuracao-email', $this->payload(['encriptacao' => 7]))->assertSessionHasErrors('encriptacao');
        $this->assertSame(0, ConfiguracaoEmail::count());
    }

    public function test_put_com_servidor_interno_da_erro_em_servidor(): void
    {
        $this->admin();
        $this->app->bind(ValidadorDeServidorSmtp::class, fn () => new ValidadorDeServidorSmtp);

        $this->put('/configuracao-email', $this->payload(['servidor' => '127.0.0.1']))->assertSessionHasErrors('servidor');
    }

    public function test_testar_com_sucesso_activa(): void
    {
        $this->admin();
        $this->existente();

        $this->post('/configuracao-email/testar', ['destino' => 'admin@exemplo.com'])->assertRedirect('/configuracao-email');

        $c = ConfiguracaoEmail::sole();
        $this->assertSame(EstadoConfiguracaoEmail::VERIFICADO, $c->estado);
        $this->assertTrue($c->activo);
    }

    public function test_testar_sem_configuracao_e_404_e_destino_invalido_da_erro(): void
    {
        $this->admin();

        $this->post('/configuracao-email/testar', ['destino' => 'admin@exemplo.com'])->assertNotFound();

        $this->existente();
        $this->post('/configuracao-email/testar', ['destino' => 'invalido'])->assertSessionHasErrors('destino');
    }

    public function test_o_sexto_teste_na_hora_leva_429(): void
    {
        $this->admin();
        $this->existente();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/configuracao-email/testar', ['destino' => 'admin@exemplo.com'])->assertRedirect();
        }

        $this->post('/configuracao-email/testar', ['destino' => 'admin@exemplo.com'])->assertStatus(429);
    }

    public function test_desactivar_desliga(): void
    {
        $this->admin();
        $this->existente(['estado' => EstadoConfiguracaoEmail::VERIFICADO, 'activo' => true]);

        $this->post('/configuracao-email/desactivar')->assertRedirect('/configuracao-email');

        $this->assertFalse(ConfiguracaoEmail::sole()->activo);
    }

    public function test_outra_escola_nunca_ve_a_configuracao_da_primeira(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->existente(['servidor' => 'smtp.escola-b.com']));
        $this->actingAs($admin);

        $this->get('/configuracao-email')->assertInertia(fn (Assert $p) => $p->where('configuracao', null));
        $this->post('/configuracao-email/desactivar')->assertNotFound();
    }

    public function test_a_pagina_diz_quando_a_plataforma_bloqueou_e_o_teste_e_recusado(): void
    {
        $this->admin();
        $this->existente();
        ConfiguracaoEmail::query()->update(['bloqueada_pela_plataforma' => true]);

        $this->get('/configuracao-email')->assertInertia(fn (Assert $p) => $p->where('configuracao.bloqueada_pela_plataforma', true));
        $this->post('/configuracao-email/testar', ['destino' => 'admin@exemplo.com'])->assertSessionHasErrors('geral');
        $this->assertFalse(ConfiguracaoEmail::sole()->activo);
    }
}
