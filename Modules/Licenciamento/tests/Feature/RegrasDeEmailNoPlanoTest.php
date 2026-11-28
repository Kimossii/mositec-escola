<?php

namespace Modules\Licenciamento\Tests\Feature;

use Modules\Licenciamento\Services\DireitosService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Licenciamento\Contracts\RegrasDeEmailDaLicenca;
use Modules\Licenciamento\Actions\CriarPlanoAction;
use Modules\Licenciamento\Actions\EmitirLicencaAction;
use Modules\Licenciamento\Assinatura\CodecoDeLicenca;
use Modules\Licenciamento\Assinatura\Exceptions\DocumentoInvalido;
use Modules\Licenciamento\Documento\DireitosDaLicenca;
use Modules\Licenciamento\DTOs\EmitirLicencaDTO;
use Modules\Licenciamento\DTOs\PlanoDTO;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Exceptions\DadosDePlanoInvalidos;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\ChavesPublicas;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Tests\TestCase;

class RegrasDeEmailNoPlanoTest extends TestCase
{
    use ComLicencaDeTeste;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararLicencas();
        $this->prepararEmissor();
        $this->congelarRelogioDeTeste();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function plano(array $sobrepor = []): Plano
    {
        return app(CriarPlanoAction::class)->executar(new PlanoDTO(...array_merge([
            'nome' => 'Básico', 'modulos' => ['notificacoes'], 'validadeDias' => 365, 'modalidade' => Modalidade::ONLINE,
        ], $sobrepor)));
    }

    public function test_plano_sem_regras_de_email_tem_omissoes(): void
    {
        $plano = $this->plano()->refresh();

        $this->assertNull($plano->limite_emails_mes);
        $this->assertSame(80, $plano->emails_aviso_pct);
        $this->assertTrue($plano->emails_bloqueia_ao_exceder);
    }

    public function test_plano_guarda_as_regras_de_email(): void
    {
        $plano = $this->plano(['limites' => ['emails_mes' => 500], 'emailsAvisoPct' => 90, 'emailsBloqueia' => false])->refresh();

        $this->assertSame(500, $plano->limite_emails_mes);
        $this->assertSame(90, $plano->emails_aviso_pct);
        $this->assertFalse($plano->emails_bloqueia_ao_exceder);
    }

    public function test_aviso_pct_e_emails_negativos_sao_recusados(): void
    {
        foreach ([['emailsAvisoPct' => 0], ['emailsAvisoPct' => 101], ['limites' => ['emails_mes' => -1]]] as $caso) {
            try {
                $this->plano($caso);
                $this->fail('Devia recusar '.json_encode($caso));
            } catch (DadosDePlanoInvalidos) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_licenca_emitida_copia_as_regras_para_a_linha_e_para_o_token(): void
    {
        $plano = $this->plano(['limites' => ['emails_mes' => 500], 'emailsAvisoPct' => 90, 'emailsBloqueia' => false]);

        $licenca = app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(planoId: $plano->id, escola: $this->tenant->codigo), null);
        $direitos = DireitosDaLicenca::deClaims(app(CodecoDeLicenca::class)->verificar($licenca->token, ChavesPublicas::daConfig()));

        $this->assertSame(500, $licenca->limite_emails_mes);
        $this->assertSame(500, $direitos->limiteEmailsMes());
        $this->assertSame(90, $direitos->emailsAvisoPct);
        $this->assertFalse($direitos->emailsBloqueia);
    }

    public function test_licenca_antiga_sem_os_campos_fica_sem_limite_e_com_omissoes(): void
    {
        $plano = $this->plano();
        $licenca = app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(planoId: $plano->id, escola: $this->tenant->codigo), null);
        $claims = DireitosDaLicenca::deClaims(app(CodecoDeLicenca::class)->verificar($licenca->token, ChavesPublicas::daConfig()))->paraClaims();
        unset($claims['eap'], $claims['ebl'], $claims['lim']['emails_mes']);

        $direitos = DireitosDaLicenca::deClaims($claims);

        $this->assertNull($direitos->limiteEmailsMes());
        $this->assertSame(80, $direitos->emailsAvisoPct);
        $this->assertTrue($direitos->emailsBloqueia);
    }

    public function test_claims_invalidos_sao_recusados(): void
    {
        $plano = $this->plano();
        $licenca = app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(planoId: $plano->id, escola: $this->tenant->codigo), null);
        $base = DireitosDaLicenca::deClaims(app(CodecoDeLicenca::class)->verificar($licenca->token, ChavesPublicas::daConfig()))->paraClaims();

        foreach ([['eap' => 0], ['eap' => 101], ['ebl' => 'x']] as $mau) {
            try {
                DireitosDaLicenca::deClaims(array_merge($base, $mau));
                $this->fail('Devia recusar '.json_encode($mau));
            } catch (DocumentoInvalido) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_escola_le_as_regras_da_licenca_instalada_e_sem_licenca_fica_sem_limite(): void
    {
        $regras = app(RegrasDeEmailDaLicenca::class);
        $this->assertNull($regras->doTenantActual()->limiteMes);

        $plano = $this->plano(['limites' => ['emails_mes' => 500], 'emailsAvisoPct' => 90, 'emailsBloqueia' => false]);
        app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(planoId: $plano->id, escola: $this->tenant->codigo), null);
        app()->forgetInstance(RegrasDeEmailDaLicenca::class);
        app()->forgetInstance(DireitosService::class);

        $lidas = app(RegrasDeEmailDaLicenca::class)->doTenantActual();

        $this->assertSame(500, $lidas->limiteMes);
        $this->assertSame(90, $lidas->avisoPct);
        $this->assertFalse($lidas->bloqueia);
    }
}
