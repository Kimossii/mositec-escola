<?php

namespace Modules\Licenciamento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Licenciamento\Exceptions\LicencaRecusada;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Services\CodigoDaInstalacao;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Tests\TestCase;

class InstalarLicencaTest extends TestCase
{
    use ComLicencaDeTeste;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararLicencas();
    }

    private function instalar(string $token, ?int $tenantId = null, ?string $codigo = null)
    {
        return app(InstalarLicencaAction::class)->executar($tenantId ?? $this->tenant->id, $codigo ?? $this->tenant->codigo, $token);
    }

    public function test_instala_licenca_valida_e_guarda_direitos(): void
    {
        $token = $this->tokenDeTeste();

        $direitos = $this->instalar($token);

        $this->assertSame('lic-1', $direitos->id);
        $linha = DireitosLocais::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('lic-1', $linha->licenca_id);
        $this->assertSame($token, $linha->token);
        $this->assertNull($linha->revogada_em);
        $this->assertNotNull($linha->instalada_em);
    }

    public function test_recusa_assinatura_invalida(): void
    {
        $outra = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        $this->expectException(LicencaRecusada::class);
        try {
            $this->instalar($this->tokenDeTeste([], $outra));
        } finally {
            $this->assertSame(0, DireitosLocais::query()->count());
        }
    }

    public function test_recusa_lixo_que_nao_e_um_token(): void
    {
        $this->expectException(LicencaRecusada::class);
        $this->instalar('isto-nao-e-um-token');
    }

    public function test_recusa_instalacao_diferente_quando_o_token_a_traz(): void
    {
        $this->expectException(LicencaRecusada::class);
        $this->instalar($this->tokenDeTeste(['instalacao' => 'INST-OUTRA']));
    }

    public function test_aceita_instalacao_igual_a_desta_instalacao(): void
    {
        $codigo = app(CodigoDaInstalacao::class)->obter();

        $this->assertSame('lic-1', $this->instalar($this->tokenDeTeste(['instalacao' => $codigo]))->id);
    }

    public function test_recusa_escola_diferente_do_tenant(): void
    {
        $this->expectException(LicencaRecusada::class);
        $this->instalar($this->tokenDeTeste(['escola' => 'MOSI-999999']));
    }

    public function test_sem_claims_de_ligacao_instala_em_qualquer_sitio(): void
    {
        $direitos = $this->instalar($this->tokenDeTeste(['escola' => null, 'instalacao' => null]));

        $this->assertSame('lic-1', $direitos->id);
    }

    public function test_recusa_licenca_mais_antiga_que_a_actual(): void
    {
        $this->instalar($this->tokenDeTeste(['jti' => 'nova', 'iat' => '2026-03-01T00:00:00+00:00']));

        try {
            $this->instalar($this->tokenDeTeste(['jti' => 'antiga', 'iat' => '2026-02-01T00:00:00+00:00']));
            $this->fail('Devia ter recusado o replay.');
        } catch (LicencaRecusada) {
            $this->assertSame('nova', DireitosLocais::query()->sole()->licenca_id);
        }
    }

    public function test_reinstalar_a_mesma_licenca_e_idempotente(): void
    {
        $token = $this->tokenDeTeste();

        $this->instalar($token);
        $this->instalar($token);

        $this->assertSame(1, DireitosLocais::query()->count());
    }

    public function test_licenca_mais_nova_substitui_a_antiga(): void
    {
        $this->instalar($this->tokenDeTeste(['jti' => 'a', 'iat' => '2026-02-01T00:00:00+00:00']));
        $this->instalar($this->tokenDeTeste(['jti' => 'b', 'iat' => '2026-03-01T00:00:00+00:00']));

        $linha = DireitosLocais::query()->sole();
        $this->assertSame('b', $linha->licenca_id);
    }

    public function test_instalar_licenca_nova_limpa_a_revogacao(): void
    {
        $this->instalar($this->tokenDeTeste(['jti' => 'a', 'iat' => '2026-02-01T00:00:00+00:00']));
        DireitosLocais::query()->update(['revogada_em' => now()]);

        $this->instalar($this->tokenDeTeste(['jti' => 'b', 'iat' => '2026-03-01T00:00:00+00:00']));

        $this->assertNull(DireitosLocais::query()->sole()->revogada_em);
    }

    public function test_codigo_da_instalacao_e_estavel_entre_chamadas(): void
    {
        $a = app(CodigoDaInstalacao::class)->obter();
        $b = (new CodigoDaInstalacao)->obter();

        $this->assertNotSame('', $a);
        $this->assertSame($a, $b);
    }
}
