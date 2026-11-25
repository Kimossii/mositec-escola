<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** 2FA opcional do super admin: activar/desactivar, desafio no login, auditoria e fugas de segredo. */
class DoisFactoresPlataformaTest extends TestCase
{
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.hosts_centrais' => [self::CENTRAL], 'session.driver' => 'database', 'session.lottery' => [0, 100]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Activa o 2FA directamente na BD de teste. */
    private function comDoisFactores(SuperAdmin $admin, array $recuperacao = ['recup-0001', 'recup-0002'], bool $confirmado = true): SuperAdmin
    {
        $admin->forceFill([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode($recuperacao)),
            'two_factor_confirmed_at' => $confirmado ? now() : null,
        ])->save();

        return $admin->fresh();
    }

    private function totp(SuperAdmin $admin): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($admin->fresh()->two_factor_secret));
    }

    /** Senha certa no login: devolve o id da sessão pendente de desafio. */
    private function loginAteAoDesafio(SuperAdmin $admin): string
    {
        $resposta = $this->pedido('POST', $this->urlCentral('/plataforma/login'), ['email' => $admin->email, 'password' => self::SENHA_DO_ADMIN]);
        $resposta->assertRedirect($this->urlCentral('/plataforma/desafio'));

        return $this->idDaSessao($resposta);
    }

    private function eAutenticado(string $sessao): bool
    {
        return $this->noPainel('GET', '/plataforma', $sessao)->isOk();
    }

    // ---- Modelo -------------------------------------------------------------------------------

    public function test_2fa_confirmado_conta_como_activo_e_o_segredo_nao_sai_no_array(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());

        $this->assertTrue($admin->hasEnabledTwoFactorAuthentication());
        $this->assertArrayNotHasKey('two_factor_secret', $admin->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $admin->toArray());
    }

    public function test_2fa_sem_confirmar_nao_conta_como_activo(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin(), confirmado: false);

        $this->assertFalse($admin->hasEnabledTwoFactorAuthentication());
    }

    // ---- Activar e desactivar -----------------------------------------------------------------

    public function test_iniciar_exige_a_senha_actual(): void
    {
        $sessao = $this->entrarNoPainel($this->superAdmin());

        $this->noPainel('POST', '/plataforma/seguranca/dois-factores', $sessao, ['password' => 'errada'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_iniciar_devolve_qr_e_chave_e_guarda_o_segredo_cifrado_sem_confirmar(): void
    {
        $admin = $this->superAdmin();
        $sessao = $this->entrarNoPainel($admin);

        $resposta = $this->noPainel('POST', '/plataforma/seguranca/dois-factores', $sessao, ['password' => self::SENHA_DO_ADMIN], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonStructure(['qr', 'chave']);

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertNotSame($resposta->json('chave'), $admin->two_factor_secret);
        $this->assertSame($resposta->json('chave'), decrypt($admin->two_factor_secret));
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertStringContainsString('<svg', $resposta->json('qr'));
    }

    public function test_confirmar_com_codigo_errado_falha_e_nao_activa(): void
    {
        $admin = $this->superAdmin();
        $sessao = $this->entrarNoPainel($admin);
        $this->noPainel('POST', '/plataforma/seguranca/dois-factores', $sessao, ['password' => self::SENHA_DO_ADMIN], ['Accept' => 'application/json'])->assertOk();

        $this->noPainel('POST', '/plataforma/seguranca/dois-factores/confirmar', $sessao, ['code' => '000000'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertFalse($admin->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame(0, RegistoDeAuditoria::query()->where('accao', 'super_admin.2fa_activado')->count());
    }

    public function test_confirmar_sem_iniciar_falha(): void
    {
        $sessao = $this->entrarNoPainel($this->superAdmin());

        $this->noPainel('POST', '/plataforma/seguranca/dois-factores/confirmar', $sessao, ['code' => '123456'], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_confirmar_com_codigo_certo_activa_devolve_8_codigos_e_audita(): void
    {
        $admin = $this->superAdmin();
        $sessao = $this->entrarNoPainel($admin);
        $this->noPainel('POST', '/plataforma/seguranca/dois-factores', $sessao, ['password' => self::SENHA_DO_ADMIN], ['Accept' => 'application/json'])->assertOk();

        $resposta = $this->noPainel('POST', '/plataforma/seguranca/dois-factores/confirmar', $sessao, ['code' => $this->totp($admin)], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertCount(8, $resposta->json('codigos'));
        $this->assertTrue($admin->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame($resposta->json('codigos'), $admin->fresh()->recoveryCodes());

        $registo = RegistoDeAuditoria::query()->where('accao', 'super_admin.2fa_activado')->sole();
        $this->assertSame($admin->id, $registo->super_admin_id);
        $this->assertStringNotContainsString($resposta->json('codigos')[0], json_encode($registo->detalhe));
    }

    public function test_iniciar_com_2fa_ja_activo_e_recusado(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $sessao = $this->sessaoAutenticadaComDesafio($admin);

        $this->noPainel('POST', '/plataforma/seguranca/dois-factores', $sessao, ['password' => self::SENHA_DO_ADMIN], ['Accept' => 'application/json'])
            ->assertStatus(409);
    }

    /** Completa o login de uma conta com 2FA activo (senha + código) e devolve a sessão aberta. */
    private function sessaoAutenticadaComDesafio(SuperAdmin $admin): string
    {
        $pendente = $this->loginAteAoDesafio($admin);
        $resposta = $this->noPainel('POST', '/plataforma/desafio', $pendente, ['code' => $this->totp($admin)]);
        $resposta->assertRedirect($this->urlCentral('/plataforma'));

        return $this->idDaSessao($resposta);
    }

    public function test_desactivar_exige_senha_limpa_as_colunas_e_audita(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $sessao = $this->sessaoAutenticadaComDesafio($admin);

        $this->noPainel('DELETE', '/plataforma/seguranca/dois-factores', $sessao, ['password' => 'errada'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertTrue($admin->fresh()->hasEnabledTwoFactorAuthentication());

        $this->noPainel('DELETE', '/plataforma/seguranca/dois-factores', $sessao, ['password' => self::SENHA_DO_ADMIN], ['Accept' => 'application/json'])->assertNoContent();

        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertSame(1, RegistoDeAuditoria::query()->where('accao', 'super_admin.2fa_desactivado')->count());
    }

    public function test_pagina_seguranca_nao_expoe_o_segredo(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $sessao = $this->sessaoAutenticadaComDesafio($admin);

        $resposta = $this->noPainel('GET', '/plataforma/seguranca', $sessao, [], $this->cabecalhosInertia())->assertOk();

        $this->assertSame('Plataforma/Seguranca', $resposta->json('component'));
        $this->assertTrue($resposta->json('props.doisFactores.activo'));
        $this->assertStringNotContainsString('two_factor', $resposta->getContent());
        $this->assertStringNotContainsString(decrypt($admin->fresh()->two_factor_secret), $resposta->getContent());
    }

    // ---- Login --------------------------------------------------------------------------------

    public function test_conta_sem_2fa_entra_como_hoje(): void
    {
        $sessao = $this->entrarNoPainel($this->superAdmin());

        $this->assertTrue($this->eAutenticado($sessao));
    }

    public function test_conta_com_2fa_vai_para_o_desafio_sem_sessao_aberta(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $pendente = $this->loginAteAoDesafio($admin);

        $this->assertFalse($this->eAutenticado($pendente));
        $this->noPainel('GET', '/plataforma/desafio', $pendente, [], $this->cabecalhosInertia())->assertOk();
    }

    public function test_2fa_nao_confirmado_nao_pede_desafio(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin(), confirmado: false);

        $sessao = $this->entrarNoPainel($admin);

        $this->assertTrue($this->eAutenticado($sessao));
    }

    public function test_codigo_certo_abre_a_sessao_e_muda_o_id(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $pendente = $this->loginAteAoDesafio($admin);

        $resposta = $this->noPainel('POST', '/plataforma/desafio', $pendente, ['code' => $this->totp($admin)]);

        $resposta->assertRedirect($this->urlCentral('/plataforma'));
        $aberta = $this->idDaSessao($resposta);
        $this->assertNotSame($pendente, $aberta);
        $this->assertTrue($this->eAutenticado($aberta));
    }

    public function test_codigo_errado_nao_abre_e_audita_a_falha(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $pendente = $this->loginAteAoDesafio($admin);

        $this->noPainel('POST', '/plataforma/desafio', $pendente, ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertFalse($this->eAutenticado($pendente));
        $registo = RegistoDeAuditoria::query()->where('accao', 'super_admin.2fa_desafio_falhado')->sole();
        $this->assertSame($admin->id, $registo->super_admin_id);
    }

    public function test_codigo_totp_ja_usado_e_recusado(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $codigo = $this->totp($admin);

        $primeiro = $this->loginAteAoDesafio($admin);
        $this->noPainel('POST', '/plataforma/desafio', $primeiro, ['code' => $codigo])->assertRedirect($this->urlCentral('/plataforma'));

        $segundo = $this->loginAteAoDesafio($admin);
        $this->noPainel('POST', '/plataforma/desafio', $segundo, ['code' => $codigo])->assertSessionHasErrors('code');
        $this->assertFalse($this->eAutenticado($segundo));
    }

    public function test_codigo_de_recuperacao_serve_uma_so_vez(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());

        $primeiro = $this->loginAteAoDesafio($admin);
        $this->noPainel('POST', '/plataforma/desafio', $primeiro, ['recovery_code' => 'recup-0001'])->assertRedirect($this->urlCentral('/plataforma'));
        $this->assertNotContains('recup-0001', $admin->fresh()->recoveryCodes());
        $this->assertCount(2, $admin->fresh()->recoveryCodes());

        $segundo = $this->loginAteAoDesafio($admin);
        $this->noPainel('POST', '/plataforma/desafio', $segundo, ['recovery_code' => 'recup-0001'])->assertSessionHasErrors('recovery_code');
    }

    public function test_cinco_falhas_bloqueiam_o_desafio_mesmo_com_o_codigo_certo(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $pendente = $this->loginAteAoDesafio($admin);

        for ($i = 0; $i < 5; $i++) {
            $this->noPainel('POST', '/plataforma/desafio', $pendente, ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->noPainel('POST', '/plataforma/desafio', $pendente, ['code' => $this->totp($admin)])->assertSessionHasErrors('code');
        $this->assertFalse($this->eAutenticado($pendente));
    }

    public function test_desafio_sem_pendente_volta_ao_login(): void
    {
        $this->superAdmin();

        $this->pedido('GET', $this->urlCentral('/plataforma/desafio'))->assertRedirect($this->urlCentral('/plataforma/login'));
        $this->pedido('POST', $this->urlCentral('/plataforma/desafio'), ['code' => '123456'])->assertRedirect($this->urlCentral('/plataforma/login'));
    }

    public function test_desafio_expirado_e_recusado(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $pendente = $this->loginAteAoDesafio($admin);

        Carbon::setTestNow(now()->addMinutes(6));

        $this->noPainel('POST', '/plataforma/desafio', $pendente, ['code' => $this->totp($admin)])->assertRedirect($this->urlCentral('/plataforma/login'));
        $this->assertFalse($this->eAutenticado($pendente));
    }

    public function test_conta_desactivada_entre_os_passos_e_recusada(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $pendente = $this->loginAteAoDesafio($admin);
        $codigo = $this->totp($admin);

        $admin->forceFill(['estado' => 0])->save();

        $this->noPainel('POST', '/plataforma/desafio', $pendente, ['code' => $codigo])->assertRedirect($this->urlCentral('/plataforma/login'));
        $this->assertFalse($this->eAutenticado($pendente));
    }

    public function test_2fa_reposto_entre_os_passos_invalida_o_desafio(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $pendente = $this->loginAteAoDesafio($admin);

        $admin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        $this->noPainel('POST', '/plataforma/desafio', $pendente, ['recovery_code' => 'recup-0001'])->assertRedirect($this->urlCentral('/plataforma/login'));
        $this->assertFalse($this->eAutenticado($pendente));
    }

    public function test_o_desafio_do_fortify_da_escola_nao_abre_sessao_da_plataforma(): void
    {
        $admin = $this->comDoisFactores($this->superAdmin());
        $pendente = $this->loginAteAoDesafio($admin);

        $this->noPainel('POST', '/two-factor-challenge', $pendente, ['code' => $this->totp($admin)]);

        $this->assertFalse($this->eAutenticado($pendente));
    }

    // ---- Fluxo completo com os payloads do Vue ------------------------------------------------

    public function test_fluxo_completo_activar_sair_e_entrar_com_codigo(): void
    {
        $admin = $this->superAdmin();
        $sessao = $this->entrarNoPainel($admin);
        $json = ['Accept' => 'application/json'];

        $this->noPainel('POST', '/plataforma/seguranca/dois-factores', $sessao, ['password' => self::SENHA_DO_ADMIN], $json)->assertOk();
        $codigos = $this->noPainel('POST', '/plataforma/seguranca/dois-factores/confirmar', $sessao, ['code' => $this->totp($admin)], $json)->assertOk()->json('codigos');

        $this->noPainel('POST', '/plataforma/logout', $sessao, [], $this->cabecalhosInertia());
        $this->assertFalse($this->eAutenticado($sessao));

        $pendente = $this->loginAteAoDesafio($admin);
        $aberta = $this->idDaSessao($this->noPainel('POST', '/plataforma/desafio', $pendente, ['recovery_code' => $codigos[0]]));
        $this->assertTrue($this->eAutenticado($aberta));

        $this->noPainel('DELETE', '/plataforma/seguranca/dois-factores', $aberta, ['password' => self::SENHA_DO_ADMIN], $json)->assertNoContent();
        $this->assertTrue($this->eAutenticado($this->entrarNoPainel($admin)));
    }
}
