<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Autenticacao\Notifications\RecuperarSenhaNotificacao;
use Modules\Autenticacao\Notifications\SenhaAlteradaNotificacao;
use Modules\Autenticacao\Support\ElegibilidadeParaRecuperarSenha;
use Modules\Autenticacao\Tests\Support\ComDoisFactores;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RecuperacaoDeSenhaTest extends TestCase
{
    use ComDoisFactores;
    use RefreshDatabase;

    private const SENHA_ANTIGA = 'senha-antiga-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.exemplo.ao', 'mail.from.address' => 'noreply@plataforma.test']);
    }

    private function conta(string $email, Perfil $perfil = Perfil::ENCARREGADO, bool $confirmado = true, array $extra = []): User
    {
        $user = User::create(['name' => 'Pai '.$email, 'email' => $email, 'password' => Hash::make(self::SENHA_ANTIGA), 'estado' => 1, ...$extra]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);

        if ($confirmado) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user->fresh();
    }

    private function ligacao(User $user): string
    {
        return (new RecuperarSenhaNotificacao)->toMail($user)->actionUrl;
    }

    private function pedir(string $email, string $host = 'http://localhost')
    {
        return $this->post($host.'/esqueci-a-senha', ['email' => $email]);
    }

    // ---- Pedir ----

    public function test_encarregado_com_email_confirmado_recebe_a_ligacao(): void
    {
        Notification::fake();
        $pai = $this->conta('pai@example.com');

        $this->pedir('pai@example.com')->assertRedirect('/esqueci-a-senha/enviado');
        $this->pedir('PAI@Example.com ');

        Notification::assertSentTo($pai, RecuperarSenhaNotificacao::class);
    }

    public function test_a_resposta_e_igual_para_qualquer_email_e_nada_e_enviado_a_quem_nao_e_elegivel(): void
    {
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class); // o limite por IP tem teste próprio
        $this->conta('admin@example.com', Perfil::ADMIN_ESCOLA);
        $this->conta('prof@example.com', Perfil::PROFESSOR);
        $this->conta('aluno@example.com', Perfil::ALUNO);
        $this->conta('porconfirmar@example.com', Perfil::ENCARREGADO, false);
        $this->conta('inactivo@example.com', Perfil::ENCARREGADO, true, ['estado' => 0]);
        $this->conta('matricula@example.com', Perfil::ENCARREGADO, true, ['tipo_login' => TipoLogin::MATRICULA]);
        $misto = $this->conta('misto@example.com');
        $misto->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->firstOrFail()->id);

        foreach (['admin', 'prof', 'aluno', 'porconfirmar', 'inactivo', 'matricula', 'misto', 'naoexiste', 'invalido'] as $nome) {
            $email = $nome === 'invalido' ? 'isto-nao-e-email' : $nome.'@example.com';
            $this->pedir($email)->assertRedirect('/esqueci-a-senha/enviado')->assertSessionHasNoErrors();
        }

        Notification::assertNothingSent();
    }

    public function test_conta_com_permissoes_atribuidas_a_parte_nao_recupera_por_email(): void
    {
        Notification::fake();
        $pai = $this->conta('pai@example.com');
        $pai->permissoes()->create(['modulo_id' => Modulo::query()->firstOrFail()->id, 'acao_id' => Acao::query()->firstOrFail()->id, 'permitido' => true]);

        $this->pedir('pai@example.com')->assertRedirect('/esqueci-a-senha/enviado');

        Notification::assertNothingSent();
        $this->assertFalse(app(ElegibilidadeParaRecuperarSenha::class)->permite($pai->fresh()));
    }

    public function test_sem_canal_de_email_nao_ha_recuperacao_nem_link_no_login(): void
    {
        Notification::fake();
        $this->conta('pai@example.com');
        config(['mail.default' => 'log']);

        $this->get('/esqueci-a-senha')->assertNotFound();
        $this->pedir('pai@example.com')->assertNotFound();
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('recuperacaoDisponivel', false));

        Notification::assertNothingSent();
    }

    public function test_o_login_so_oferece_o_link_com_canal_de_email(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('recuperacaoDisponivel', true));

        config(['comunicacao.recuperacao_senha.perfis' => []]);
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('recuperacaoDisponivel', false));
    }

    public function test_limite_de_pedidos_por_hora_conta_tambem_os_emails_sem_conta(): void
    {
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['comunicacao.recuperacao_senha.pedidos_por_hora' => 2]);
        $pai = $this->conta('pai@example.com');

        foreach (range(1, 4) as $_) {
            $this->pedir('pai@example.com')->assertRedirect('/esqueci-a-senha/enviado');
        }

        Notification::assertSentToTimes($pai, RecuperarSenhaNotificacao::class, 2);
    }

    public function test_o_mesmo_email_em_duas_escolas_so_recupera_a_da_escola_do_pedido(): void
    {
        Notification::fake();
        $outra = $this->criarTenant('MOSI-000002', 'Colégio B', 'colegiob.ao');
        $paiA = $this->conta('pai@example.com');
        $paiB = $this->noTenant($outra, function () {
            $this->seed(PermissaoDatabaseSeeder::class); // os perfis são por escola
            return $this->conta('pai@example.com');
        });

        $this->pedir('pai@example.com', 'http://colegiob.ao')->assertRedirect('/esqueci-a-senha/enviado');

        Notification::assertSentTo($paiB, RecuperarSenhaNotificacao::class);
        Notification::assertNotSentTo($paiA, RecuperarSenhaNotificacao::class);
    }

    public function test_num_host_central_sem_escola_nao_ha_recuperacao(): void
    {
        config(['tenancy.hosts_centrais' => ['central.test']]);

        $this->get('http://central.test/esqueci-a-senha')->assertNotFound();
        $this->post('http://central.test/esqueci-a-senha', ['email' => 'pai@example.com'])->assertNotFound();
    }

    public function test_o_limite_por_ip_corta_os_pedidos_em_excesso(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->pedir("alguem{$i}@example.com")->assertRedirect('/esqueci-a-senha/enviado');
        }

        $this->pedir('alguem6@example.com')->assertStatus(429);
    }

    // ---- A ligação ----

    public function test_a_ligacao_aponta_a_escola_certa_e_nao_leva_segredos(): void
    {
        $outra = $this->criarTenant('MOSI-000002', 'Colégio B', 'colegiob.ao');
        $paiB = $this->noTenant($outra, function () {
            $this->seed(PermissaoDatabaseSeeder::class);
            return $this->conta('pai-b@example.com');
        });

        $ligacao = $this->noTenant($outra, fn () => $this->ligacao($paiB));

        $this->assertStringStartsWith('http://colegiob.ao/recuperar-senha/'.$paiB->id.'/', $ligacao);
        $this->assertStringContainsString('signature=', $ligacao);
        $this->assertStringContainsString('expires=', $ligacao);
        $this->assertStringNotContainsString('pai-b@example.com', $ligacao);
        $this->assertStringNotContainsString(self::SENHA_ANTIGA, $ligacao);
        $this->assertStringNotContainsString($paiB->password, $ligacao);
    }

    public function test_ligacao_adulterada_ou_expirada_e_recusada(): void
    {
        $pai = $this->conta('pai@example.com');
        $ligacao = $this->ligacao($pai);

        $this->get($ligacao.'x')->assertForbidden();
        $this->post($ligacao.'x', ['password' => 'Nova-Senha-123!', 'password_confirmation' => 'Nova-Senha-123!'])->assertForbidden();

        Carbon::setTestNow(now()->addMinutes(61));
        $this->get($ligacao)->assertForbidden();
        $this->post($ligacao, ['password' => 'Nova-Senha-123!', 'password_confirmation' => 'Nova-Senha-123!'])->assertForbidden();
        Carbon::setTestNow();

        $this->assertTrue(Hash::check(self::SENHA_ANTIGA, $pai->fresh()->password));
    }

    public function test_a_ligacao_abre_o_formulario_sem_sessao(): void
    {
        $pai = $this->conta('pai@example.com');

        $this->get($this->ligacao($pai))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Autenticacao/RecuperarSenha')->where('estado', 'formulario'));
    }

    // ---- Concluir ----

    public function test_concluir_muda_a_senha_termina_sessoes_e_tokens_e_avisa(): void
    {
        Notification::fake();
        $pai = $this->conta('pai@example.com');
        $pai->createToken('telemovel');
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $pai->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        $tokenAntigo = $pai->fresh()->remember_token;

        $this->post($this->ligacao($pai), ['password' => 'Nova-Senha-123!', 'password_confirmation' => 'Nova-Senha-123!'])
            ->assertRedirect('/senha-redefinida');

        $pai = $pai->fresh();
        $this->assertTrue(Hash::check('Nova-Senha-123!', $pai->password));
        $this->assertFalse(Hash::check(self::SENHA_ANTIGA, $pai->password));
        $this->assertFalse((bool) $pai->deve_alterar_senha);
        $this->assertNotSame($tokenAntigo, $pai->remember_token);
        $this->assertSame(0, $pai->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $pai->id)->count());
        Notification::assertSentTo($pai, SenhaAlteradaNotificacao::class);
    }

    public function test_a_ligacao_so_serve_uma_vez(): void
    {
        Notification::fake();
        $pai = $this->conta('pai@example.com');
        $ligacao = $this->ligacao($pai);

        $this->post($ligacao, ['password' => 'Primeira-Senha-1!', 'password_confirmation' => 'Primeira-Senha-1!'])->assertRedirect('/senha-redefinida');
        $this->post($ligacao, ['password' => 'Segunda-Senha-2!', 'password_confirmation' => 'Segunda-Senha-2!']);

        $this->assertTrue(Hash::check('Primeira-Senha-1!', $pai->fresh()->password));
        $this->get($ligacao)->assertInertia(fn (Assert $p) => $p->where('estado', 'invalido'));
        Notification::assertSentToTimes($pai, SenhaAlteradaNotificacao::class, 1);
    }

    public function test_mudar_o_email_ou_a_confirmacao_invalida_a_ligacao(): void
    {
        $pai = $this->conta('pai@example.com');
        $ligacao = $this->ligacao($pai);

        $pai->update(['email' => 'novo@example.com']);

        $this->get($ligacao)->assertInertia(fn (Assert $p) => $p->where('estado', 'invalido'));
        $this->post($ligacao, ['password' => 'Nova-Senha-123!', 'password_confirmation' => 'Nova-Senha-123!']);
        $this->assertTrue(Hash::check(self::SENHA_ANTIGA, $pai->fresh()->password));
    }

    public function test_deixar_de_ser_elegivel_entre_o_pedido_e_o_clique_invalida_a_ligacao(): void
    {
        $pai = $this->conta('pai@example.com');
        $ligacao = $this->ligacao($pai);

        $pai->update(['estado' => 0]);

        $this->get($ligacao)->assertInertia(fn (Assert $p) => $p->where('estado', 'invalido'));
        $this->post($ligacao, ['password' => 'Nova-Senha-123!', 'password_confirmation' => 'Nova-Senha-123!']);
        $this->assertTrue(Hash::check(self::SENHA_ANTIGA, $pai->fresh()->password));
    }

    public function test_senha_fraca_ou_sem_confirmacao_nao_muda_nada(): void
    {
        Notification::fake();
        $pai = $this->conta('pai@example.com');
        $ligacao = $this->ligacao($pai);

        $this->post($ligacao, ['password' => '123', 'password_confirmation' => '123'])->assertSessionHasErrors('password');
        $this->post($ligacao, ['password' => 'Nova-Senha-123!', 'password_confirmation' => 'outra'])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::SENHA_ANTIGA, $pai->fresh()->password));
        Notification::assertNothingSent();
    }

    public function test_o_2fa_nao_e_contornado_nem_desligado(): void
    {
        Notification::fake();
        $pai = $this->comDoisFactores($this->conta('pai@example.com'));

        $this->post($this->ligacao($pai), ['password' => 'Nova-Senha-123!', 'password_confirmation' => 'Nova-Senha-123!'])->assertRedirect('/senha-redefinida');

        $pai = $pai->fresh();
        $this->assertTrue($pai->hasEnabledTwoFactorAuthentication());
        $this->assertNotNull($pai->two_factor_secret);
    }

    public function test_uma_ligacao_de_outra_escola_nunca_muda_a_senha_de_um_utilizador_desta(): void
    {
        $outra = $this->criarTenant('MOSI-000002', 'Colégio B', 'colegiob.ao');
        $paiA = $this->conta('pai@example.com');
        $ligacaoDeA = $this->ligacao($paiA);

        // O link da escola A não vale no domínio da escola B (a assinatura inclui o domínio).
        $this->post(str_replace('http://localhost', 'http://colegiob.ao', $ligacaoDeA), ['password' => 'Nova-Senha-123!', 'password_confirmation' => 'Nova-Senha-123!'])->assertForbidden();

        $this->assertTrue(Hash::check(self::SENHA_ANTIGA, $paiA->fresh()->password));
    }

    public function test_as_paginas_publicas_nao_levam_o_layout_da_aplicacao(): void
    {
        $pai = $this->conta('pai@example.com');

        foreach (['/esqueci-a-senha', '/esqueci-a-senha/enviado', '/senha-redefinida', $this->ligacao($pai)] as $url) {
            $this->assertStringNotContainsString('id="app-header"', $this->get($url)->assertOk()->getContent(), $url);
        }
    }
}
