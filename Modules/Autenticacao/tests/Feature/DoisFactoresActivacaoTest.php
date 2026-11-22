<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Autenticacao\Tests\Support\ComDoisFactores;
use Modules\Usuario\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class DoisFactoresActivacaoTest extends TestCase
{
    use RefreshDatabase;
    use ComDoisFactores;

    private function utilizador(): User
    {
        return User::create(['name' => 'Ana Silva', 'email' => 'ana@example.com', 'password' => Hash::make('senha-segura-123')]);
    }

    public function test_utilizador_activa_confirma_e_desactiva_2fa(): void
    {
        $ana = $this->utilizador();
        $this->actingAs($ana)->postJson('/user/confirm-password', ['password' => 'senha-segura-123'])->assertCreated();

        $this->postJson('/user/two-factor-authentication')->assertOk();
        $ana = $ana->fresh();
        $this->assertNotNull($ana->two_factor_secret);
        $this->assertFalse($ana->hasEnabledTwoFactorAuthentication(), 'sem confirmar ainda não está activo');

        $codigo = app(Google2FA::class)->getCurrentOtp(decrypt($ana->two_factor_secret));
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $codigo])->assertOk();
        $this->assertTrue($ana->fresh()->hasEnabledTwoFactorAuthentication());

        $this->deleteJson('/user/two-factor-authentication')->assertOk();
        $this->assertNull($ana->fresh()->two_factor_secret);
    }

    public function test_segredo_nunca_aparece_na_serializacao(): void
    {
        $ana = $this->comDoisFactores($this->utilizador());

        $this->assertArrayNotHasKey('two_factor_secret', $ana->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $ana->toArray());
    }

    public function test_activar_sem_confirmar_senha_e_recusado(): void
    {
        $ana = $this->utilizador();

        $this->actingAs($ana)->postJson('/user/two-factor-authentication')->assertStatus(423);
        $this->assertNull($ana->fresh()->two_factor_secret);
    }
}
