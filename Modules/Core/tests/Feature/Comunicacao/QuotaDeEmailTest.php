<?php

namespace Modules\Core\Tests\Feature\Comunicacao;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\ConsumoDeEmail;
use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Comunicacao\Email\Notificacoes\QuotaDeEmailEsgotadaNotificacao;
use Modules\Core\Comunicacao\Email\Notificacoes\QuotaDeEmailNoAvisoNotificacao;
use Modules\Core\Comunicacao\Email\RegrasDeEmail;
use Modules\Core\Comunicacao\Email\ResultadoConsumo;
use Modules\Core\Licenciamento\Contracts\RegrasDeEmailDaLicenca;
use Modules\Core\Models\EnvioEmail;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\Fixtures\Comunicacao\NotificacaoDeTeste;
use Tests\TestCase;

class QuotaDeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function regras(?int $limite, int $aviso = 80, bool $bloqueia = true): void
    {
        $this->app->instance(RegrasDeEmailDaLicenca::class, new class(new RegrasDeEmail($limite, $aviso, $bloqueia)) implements RegrasDeEmailDaLicenca
        {
            public function __construct(private RegrasDeEmail $regras) {}

            public function doTenantActual(): RegrasDeEmail
            {
                return $this->regras;
            }
        });
        $this->app->forgetInstance(ConsumoDeEmail::class);
    }

    private function consumir(): ResultadoConsumo
    {
        return app(ConsumoDeEmail::class)->tentarConsumir();
    }

    public function test_sem_limite_conta_mas_nunca_bloqueia(): void
    {
        $this->regras(null);

        foreach (range(1, 5) as $_) {
            $this->assertSame(ResultadoConsumo::PERMITIDO, $this->consumir());
        }

        $this->assertSame(5, app(ConsumoDeEmail::class)->doMes());
    }

    public function test_aviso_uma_vez_limite_exacto_e_bloqueio_a_seguir(): void
    {
        $this->regras(10, 80);

        $resultados = array_map(fn () => $this->consumir(), range(1, 10));

        $this->assertSame(ResultadoConsumo::PERMITIDO, $resultados[6]);
        $this->assertSame(ResultadoConsumo::PERMITIDO_NO_AVISO, $resultados[7]);
        $this->assertSame(ResultadoConsumo::PERMITIDO, $resultados[8]);
        $this->assertSame(ResultadoConsumo::PERMITIDO_NO_LIMITE, $resultados[9]);
        $this->assertSame(ResultadoConsumo::BLOQUEADO, $this->consumir());
        $this->assertSame(10, app(ConsumoDeEmail::class)->doMes());
    }

    public function test_sem_bloqueio_continua_a_contar_acima_do_limite(): void
    {
        $this->regras(2, 80, false);

        $this->consumir();
        $this->assertSame(ResultadoConsumo::PERMITIDO_NO_LIMITE, $this->consumir());
        $this->assertSame(ResultadoConsumo::PERMITIDO, $this->consumir());
        $this->assertSame(3, app(ConsumoDeEmail::class)->doMes());
    }

    public function test_novo_mes_recomeca_a_zero_e_volta_a_avisar(): void
    {
        $this->regras(5, 80);
        CarbonImmutable::setTestNow('2026-11-15 10:00:00');
        foreach (range(1, 4) as $_) {
            $this->consumir();
        }
        $this->assertSame(4, app(ConsumoDeEmail::class)->doMes());

        CarbonImmutable::setTestNow('2026-12-01 00:00:01');
        $this->assertSame(0, app(ConsumoDeEmail::class)->doMes());
        foreach (range(1, 3) as $_) {
            $this->consumir();
        }
        $this->assertSame(ResultadoConsumo::PERMITIDO_NO_AVISO, $this->consumir());
    }

    public function test_cada_escola_tem_o_seu_contador(): void
    {
        $this->regras(1);
        $outra = $this->criarTenant('MOSI-000002', 'Colégio B', 'colegiob.ao');

        $this->assertSame(ResultadoConsumo::PERMITIDO_NO_LIMITE, $this->consumir());
        $this->noTenant($outra, fn () => $this->assertSame(ResultadoConsumo::PERMITIDO_NO_LIMITE, $this->consumir()));
        $this->assertSame(ResultadoConsumo::BLOQUEADO, $this->consumir());
    }

    public function test_opcional_acima_da_quota_nao_sai_e_fica_registado_mas_o_essencial_sai(): void
    {
        $this->regras(1);
        $destino = new AnonymousNotifiable;
        $opcional = new NotificacaoDeTeste(CategoriaEmail::OPCIONAL);

        $this->assertTrue($opcional->shouldSend($destino, 'mail'));
        $this->assertFalse($opcional->shouldSend($destino, 'mail'));
        $this->assertTrue((new NotificacaoDeTeste(CategoriaEmail::ESSENCIAL))->shouldSend($destino, 'mail'));

        $this->assertSame(1, EnvioEmail::query()->where('estado', EstadoEnvioEmail::DESCARTADO_QUOTA)->count());
        $this->assertSame(1, app(ConsumoDeEmail::class)->doMes()); // o descartado não gastou quota
    }

    public function test_escola_sem_limite_envia_opcionais_como_dantes(): void
    {
        $this->regras(null);

        foreach (range(1, 3) as $_) {
            $this->assertTrue((new NotificacaoDeTeste(CategoriaEmail::OPCIONAL))->shouldSend(new AnonymousNotifiable, 'mail'));
        }
    }

    public function test_avisos_vao_ao_email_institucional_uma_so_vez_por_mes(): void
    {
        Notification::fake();
        Estabelecimento::query()->update(['email' => 'secretaria@escola-teste.ao']);
        $this->regras(5, 80);
        $destino = new AnonymousNotifiable;

        foreach (range(1, 8) as $_) {
            (new NotificacaoDeTeste(CategoriaEmail::OPCIONAL))->shouldSend($destino, 'mail');
        }

        Notification::assertSentOnDemandTimes(QuotaDeEmailNoAvisoNotificacao::class, 1);
        Notification::assertSentOnDemandTimes(QuotaDeEmailEsgotadaNotificacao::class, 2); // ao esgotar (5.º) e ao 1.º bloqueio (6.º)
    }

    public function test_sem_email_institucional_nao_ha_aviso_mas_o_bloqueio_funciona(): void
    {
        Notification::fake();
        Estabelecimento::query()->update(['email' => null]);
        $this->regras(1);

        (new NotificacaoDeTeste(CategoriaEmail::OPCIONAL))->shouldSend(new AnonymousNotifiable, 'mail');
        $this->assertFalse((new NotificacaoDeTeste(CategoriaEmail::OPCIONAL))->shouldSend(new AnonymousNotifiable, 'mail'));

        Notification::assertNothingSent();
    }
}
