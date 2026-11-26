<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Autenticacao\Notifications\ConfirmarEmailNotificacao;
use Modules\Autenticacao\Support\HashDeConfirmacaoDeEmail;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ConfirmacaoDeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Um canal de email "configurado" (os testes correm com `array`, que não conta como disponível).
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.exemplo.ao', 'mail.from.address' => 'noreply@plataforma.test']);
    }

    private function utilizador(string $email = 'pai@example.com', array $extra = []): User
    {
        return User::create(['name' => 'Pai Silva', 'email' => $email, 'password' => Hash::make('x'), ...$extra]);
    }

    private function ligacaoPara(User $utilizador): string
    {
        return (new ConfirmarEmailNotificacao)->toMail($utilizador)->actionUrl;
    }

    public function test_pedir_envia_a_ligacao_so_ao_proprio_utilizador(): void
    {
        Notification::fake();
        $pai = $this->utilizador();
        $outro = $this->utilizador('outro@example.com');

        $this->actingAs($pai)->post('/definicoes/email/confirmacao')->assertSessionHasNoErrors();

        Notification::assertSentTo($pai, ConfirmarEmailNotificacao::class);
        Notification::assertNotSentTo($outro, ConfirmarEmailNotificacao::class);
    }

    public function test_sem_canal_de_email_configurado_nao_envia_e_avisa(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);

        $this->actingAs($this->utilizador())->post('/definicoes/email/confirmacao')->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_conta_sem_email_de_login_ou_ja_confirmada_nao_envia(): void
    {
        Notification::fake();
        $matricula = $this->utilizador('x@example.com', ['tipo_login' => TipoLogin::MATRICULA]);
        $confirmado = $this->utilizador('ok@example.com');
        $confirmado->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($matricula)->post('/definicoes/email/confirmacao')->assertSessionHasErrors('email');
        $this->actingAs($confirmado)->post('/definicoes/email/confirmacao')->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_limite_de_pedidos_por_hora(): void
    {
        Notification::fake();
        config(['comunicacao.confirmacao_email.pedidos_por_hora' => 2]);
        $pai = $this->utilizador();

        $this->actingAs($pai)->post('/definicoes/email/confirmacao')->assertSessionHasNoErrors();
        $this->actingAs($pai)->post('/definicoes/email/confirmacao')->assertSessionHasNoErrors();
        $this->actingAs($pai)->post('/definicoes/email/confirmacao')->assertSessionHasErrors('email');

        Notification::assertSentToTimes($pai, ConfirmarEmailNotificacao::class, 2);
    }

    public function test_a_ligacao_aponta_ao_dominio_da_escola_e_nao_leva_dados_sensiveis(): void
    {
        $outra = $this->criarTenant('MOSI-000002', 'Colégio B', 'colegiob.ao');
        $paiB = $this->noTenant($outra, fn () => $this->utilizador('pai-b@example.com'));

        $ligacao = $this->noTenant($outra, fn () => $this->ligacaoPara($paiB));

        $this->assertStringStartsWith('http://colegiob.ao/confirmar-email/'.$paiB->id.'/', $ligacao);
        $this->assertStringContainsString('signature=', $ligacao);
        $this->assertStringContainsString('expires=', $ligacao);
        $this->assertStringNotContainsString('pai-b@example.com', $ligacao);
        $this->assertStringNotContainsString('password', $ligacao);
    }

    public function test_abrir_a_ligacao_confirma_sem_sessao_e_e_idempotente(): void
    {
        $pai = $this->utilizador();
        $ligacao = $this->ligacaoPara($pai);

        $this->get($ligacao)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Autenticacao/EmailConfirmado')->where('resultado', 'confirmado'));
        $this->assertNotNull($pai->fresh()->email_verified_at);

        $this->get($ligacao)->assertInertia(fn (Assert $p) => $p->where('resultado', 'ja_confirmado'));
    }

    public function test_a_pagina_de_confirmacao_e_publica_sem_o_layout_da_aplicacao(): void
    {
        $pai = $this->utilizador();

        $html = $this->get($this->ligacaoPara($pai))->assertOk()->getContent();

        // O layout da aplicação monta o cabeçalho e a barra lateral; o de visitante não.
        $this->assertStringNotContainsString('id="app-header"', $html);
    }

    public function test_ligacao_adulterada_ou_expirada_e_recusada(): void
    {
        $pai = $this->utilizador();
        $ligacao = $this->ligacaoPara($pai);

        $this->get($ligacao.'x')->assertForbidden();
        $this->get(str_replace('/'.$pai->id.'/', '/'.($pai->id + 1).'/', $ligacao))->assertForbidden();

        Carbon::setTestNow(now()->addMinutes(61));
        $this->get($ligacao)->assertForbidden();
        Carbon::setTestNow();

        $this->assertNull($pai->fresh()->email_verified_at);
    }

    public function test_mudar_o_email_invalida_a_confirmacao_e_as_ligacoes_antigas(): void
    {
        $pai = $this->utilizador();
        $ligacaoAntiga = $this->ligacaoPara($pai);
        $this->get($ligacaoAntiga);
        $this->assertNotNull($pai->fresh()->email_verified_at);

        $pai->update(['email' => 'novo@example.com']);

        $this->assertNull($pai->fresh()->email_verified_at, 'O novo endereço ainda não foi confirmado.');

        $this->get($ligacaoAntiga)->assertInertia(fn (Assert $p) => $p->where('resultado', 'invalido'));
        $this->assertNull($pai->fresh()->email_verified_at);
    }

    public function test_uma_ligacao_de_outra_escola_nunca_confirma_um_utilizador_desta(): void
    {
        $outra = $this->criarTenant('MOSI-000002', 'Colégio B', 'colegiob.ao');
        $paiA = $this->utilizador('pai@example.com');

        // Assinatura VÁLIDA no domínio da escola B, mas com o id de um utilizador da escola A.
        $ligacao = $this->noTenant($outra, function () use ($paiA) {
            URL::forceRootUrl('http://colegiob.ao');

            try {
                return URL::temporarySignedRoute('email.confirmar', now()->addMinutes(60), ['id' => $paiA->id, 'hash' => HashDeConfirmacaoDeEmail::de($paiA)]);
            } finally {
                URL::forceRootUrl(null);
            }
        });

        $this->get($ligacao)->assertInertia(fn (Assert $p) => $p->where('resultado', 'invalido'));
        $this->assertNull($paiA->fresh()->email_verified_at);

        // E o link da escola A não vale no domínio da B (a assinatura inclui o domínio).
        $this->get(str_replace('localhost', 'colegiob.ao', $this->ligacaoPara($paiA)))->assertForbidden();
    }

    public function test_as_definicoes_so_oferecem_confirmar_com_canal_de_email(): void
    {
        $pai = $this->utilizador();

        $this->actingAs($pai)->get('/definicoes')->assertInertia(fn (Assert $p) => $p
            ->where('email.aplicavel', true)->where('email.confirmado', false)->where('email.podeConfirmar', true));

        config(['mail.default' => 'log']);
        $this->actingAs($pai)->get('/definicoes')->assertInertia(fn (Assert $p) => $p
            ->where('email.aplicavel', true)->where('email.podeConfirmar', false));

        $matricula = $this->utilizador('m@example.com', ['tipo_login' => TipoLogin::MATRICULA]);
        $this->actingAs($matricula)->get('/definicoes')->assertInertia(fn (Assert $p) => $p->where('email.aplicavel', false));
    }
}
