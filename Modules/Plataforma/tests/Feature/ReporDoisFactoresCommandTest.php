<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use Tests\TestCase;

class ReporDoisFactoresCommandTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $comDoisFactores = true): SuperAdmin
    {
        $admin = SuperAdmin::create(['name' => 'Op', 'email' => 'op@plataforma.test', 'password' => 'x']);

        if ($comDoisFactores) {
            $admin->forceFill([
                'two_factor_secret' => encrypt('SEGREDO'),
                'two_factor_recovery_codes' => encrypt(json_encode(['a'])),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        return $admin->fresh();
    }

    public function test_repoe_o_2fa_e_audita_com_autor_nulo(): void
    {
        $admin = $this->admin();

        $this->artisan('mosi:plataforma:admin:2fa-reset', ['--email' => 'OP@plataforma.test'])->assertSuccessful();

        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertFalse($admin->hasEnabledTwoFactorAuthentication());

        $registo = RegistoDeAuditoria::query()->where('accao', 'super_admin.2fa_reposto')->sole();
        $this->assertNull($registo->super_admin_id);
        $this->assertSame(['super_admin_id' => $admin->id], $registo->detalhe);
    }

    public function test_email_inexistente_falha_sem_alterar_nada(): void
    {
        $admin = $this->admin();

        $this->artisan('mosi:plataforma:admin:2fa-reset', ['--email' => 'x@plataforma.test'])->assertFailed();

        $this->assertTrue($admin->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame(0, RegistoDeAuditoria::query()->count());
    }

    public function test_sem_2fa_activo_avisa_e_nao_audita(): void
    {
        $this->admin(comDoisFactores: false);

        $this->artisan('mosi:plataforma:admin:2fa-reset', ['--email' => 'op@plataforma.test'])->assertSuccessful();

        $this->assertSame(0, RegistoDeAuditoria::query()->count());
    }
}
