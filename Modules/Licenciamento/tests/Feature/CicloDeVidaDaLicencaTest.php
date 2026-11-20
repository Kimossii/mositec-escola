<?php

namespace Modules\Licenciamento\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Licenciamento\Actions\CancelarLicencaAction;
use Modules\Licenciamento\Actions\CriarPlanoAction;
use Modules\Licenciamento\Actions\EmitirLicencaAction;
use Modules\Licenciamento\Actions\EstenderToleranciaAction;
use Modules\Licenciamento\Actions\ReactivarLicencaAction;
use Modules\Licenciamento\Actions\RenovarLicencaAction;
use Modules\Licenciamento\Actions\SuspenderLicencaAction;
use Modules\Licenciamento\Assinatura\CodecoDeLicenca;
use Modules\Licenciamento\Documento\DireitosDaLicenca;
use Modules\Licenciamento\DTOs\EmitirLicencaDTO;
use Modules\Licenciamento\DTOs\PlanoDTO;
use Modules\Licenciamento\DTOs\RenovarLicencaDTO;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Exceptions\EmissorIndisponivel;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Models\EventoDeLicenca;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\AvaliadorDeLicenca;
use Modules\Licenciamento\Services\ChavesPublicas;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CicloDeVidaDaLicencaTest extends TestCase
{
    use ComLicencaDeTeste;
    use RefreshDatabase;

    private const GENERICA = 'Ocorreu um erro. Tente mais tarde.';

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

    private function licenca(array $sobrepor = []): LicencaEmitida
    {
        $plano = app(CriarPlanoAction::class)->executar(new PlanoDTO(
            nome: 'Básico', modulos: ['financeiro'], validadeDias: 365, modalidade: Modalidade::ONLINE,
            limites: ['alunos' => 200], toleranciaDias: 7, avisoDias: 20,
        ));

        return app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(...array_merge([
            'planoId' => $plano->id, 'escola' => $this->tenant->codigo,
        ], $sobrepor)), 1);
    }

    private function direitos(LicencaEmitida $l): DireitosDaLicenca
    {
        return DireitosDaLicenca::deClaims(app(CodecoDeLicenca::class)->verificar($l->token, ChavesPublicas::daConfig()));
    }

    private function avancar(string $instante): void
    {
        $this->congelarRelogioDeTeste($instante);
    }

    private function aluno(): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $u = User::create(['name' => 'Aluno', 'email' => 'al@example.com', 'password' => Hash::make('x')]);
        $u->roles()->attach(Role::where('nome', Perfil::ALUNO->value)->first()->id);

        return $u;
    }

    private function eventos(LicencaEmitida $l, string $tipo)
    {
        return EventoDeLicenca::query()->where('licenca_id', $l->id)->where('tipo', $tipo)->get();
    }

    public function test_renovar_emite_nova_licenca_e_substitui_no_saas(): void
    {
        $antiga = $this->licenca();
        $this->avancar('2026-06-02T00:00:00+00:00');

        $nova = app(RenovarLicencaAction::class)->executar($antiga, new RenovarLicencaDTO(limites: ['alunos' => 300]), 'Renovação anual', 9);

        $this->assertNotSame($antiga->id, $nova->id);
        $this->assertTrue($nova->emitida_em->gt($antiga->emitida_em));
        $this->assertSame(300, $this->direitos($nova)->limites['alunos']);
        $this->assertSame(['financeiro'], $this->direitos($nova)->modulos);
        $this->assertSame(EstadoComercial::ACTIVA, $nova->estado_comercial);
        $this->assertSame(EstadoComercial::SUBSTITUIDA, $antiga->fresh()->estado_comercial);
        $this->assertSame($nova->id, DireitosLocais::query()->where('tenant_id', $this->tenant->id)->firstOrFail()->licenca_id);

        $this->assertSame($antiga->id, $this->eventos($nova, 'emitida')->sole()->detalhe['substitui_licenca_id']);
        $evento = $this->eventos($antiga, 'renovada')->sole();
        $this->assertSame(9, $evento->super_admin_id);
        $this->assertSame('Renovação anual', $evento->motivo);
        $this->assertSame($nova->id, $evento->detalhe['nova_licenca_id']);
    }

    public function test_renovar_no_mesmo_segundo_garante_iat_posterior(): void
    {
        $antiga = $this->licenca();

        $nova = app(RenovarLicencaAction::class)->executar($antiga, new RenovarLicencaDTO, 'Mesmo segundo', 1);

        $this->assertTrue($nova->emitida_em->gt($antiga->emitida_em));
        $this->assertSame($nova->id, DireitosLocais::query()->where('tenant_id', $this->tenant->id)->firstOrFail()->licenca_id);
    }

    public function test_estender_exige_motivo_e_regista_autor(): void
    {
        $antiga = $this->licenca();

        $nova = app(EstenderToleranciaAction::class)->executar($antiga, 30, 'Cortesia: pagamento em curso', 4);

        $evento = $this->eventos($antiga, 'estendida')->sole();
        $this->assertSame(4, $evento->super_admin_id);
        $this->assertSame('Cortesia: pagamento em curso', $evento->motivo);
        $this->assertSame(['tolerancia_anterior' => 7, 'tolerancia_nova' => 30, 'nova_licenca_id' => $nova->id], $evento->detalhe);
        $this->assertSame(30, $this->direitos($nova)->toleranciaDias);
        $this->assertTrue($this->direitos($nova)->expiraEm->equalTo($this->direitos($antiga)->expiraEm));
        $this->assertSame($nova->id, DireitosLocais::query()->where('tenant_id', $this->tenant->id)->firstOrFail()->licenca_id);
    }

    public function test_a_extensao_e_licenca_nova_nunca_automatica(): void
    {
        $antiga = $this->licenca();
        $d = $this->direitos($antiga);
        $limite = $d->bloqueiaEm();

        // Sem acção do emissor, passada a tolerância bloqueia e continua a bloquear.
        $depois = $limite->addDay();
        $this->assertSame('bloqueado', AvaliadorDeLicenca::modo($d, $depois, 30)->value);
        $this->assertTrue($this->direitos($antiga->fresh())->bloqueiaEm()->equalTo($limite));

        $this->avancar('2026-06-02T00:00:00+00:00');
        $nova = app(EstenderToleranciaAction::class)->executar($antiga, 20, 'Cortesia', 1);

        // A licença antiga não foi alterada; só a nova leva a tolerância maior.
        $this->assertTrue($this->direitos($antiga->fresh())->bloqueiaEm()->equalTo($limite));
        $this->assertTrue($this->direitos($nova)->bloqueiaEm()->gt($limite));
        $this->assertSame(7, $antiga->fresh()->tolerancia_dias);
    }

    public function test_estender_so_aceita_tolerancia_maior(): void
    {
        $antiga = $this->licenca();

        foreach ([7, 3, DireitosDaLicenca::MAX_DIAS + 1] as $valor) {
            try {
                app(EstenderToleranciaAction::class)->executar($antiga, $valor, 'Cortesia', 1);
                $this->fail("Devia recusar {$valor}");
            } catch (DadosDeLicencaInvalidos $e) {
                $this->assertArrayHasKey('tolerancia_dias', $e->erros);
            }
        }
        $this->assertSame(0, $this->eventos($antiga, 'estendida')->count());
        $this->assertSame(1, LicencaEmitida::query()->count());

        app(EstenderToleranciaAction::class)->executar($antiga, DireitosDaLicenca::MAX_DIAS, 'Máximo', 1);
        $this->assertSame(1, $this->eventos($antiga, 'estendida')->count());
    }

    public function test_suspender_bloqueia_de_imediato_no_saas(): void
    {
        $l = $this->licenca();
        $aluno = $this->aluno();
        $this->actingAs($aluno)->get('/')->assertOk();

        app(SuspenderLicencaAction::class)->executar($l, 'Falta de pagamento', 3);

        $this->assertSame(EstadoComercial::SUSPENSA, $l->fresh()->estado_comercial);
        $this->assertNotNull(DireitosLocais::query()->where('tenant_id', $this->tenant->id)->firstOrFail()->revogada_em);
        $this->actingAs($aluno)->get('/')->assertStatus(503)->assertSee(self::GENERICA);
        $evento = $this->eventos($l, 'suspensa')->sole();
        $this->assertSame(3, $evento->super_admin_id);
        $this->assertSame('Falta de pagamento', $evento->motivo);
    }

    public function test_reactivar_levanta_o_bloqueio(): void
    {
        $l = $this->licenca();
        $aluno = $this->aluno();
        app(SuspenderLicencaAction::class)->executar($l, 'Falta de pagamento', 3);

        app(ReactivarLicencaAction::class)->executar($l, 'Pagamento recebido', 3);

        $this->assertSame(EstadoComercial::ACTIVA, $l->fresh()->estado_comercial);
        $this->assertNull(DireitosLocais::query()->where('tenant_id', $this->tenant->id)->firstOrFail()->revogada_em);
        $this->actingAs($aluno)->get('/')->assertOk();
        $this->assertSame(1, $this->eventos($l, 'reactivada')->count());
    }

    public function test_cancelar_e_definitivo(): void
    {
        $l = $this->licenca();
        $aluno = $this->aluno();

        app(CancelarLicencaAction::class)->executar($l, 'Rescisão', 2);

        $this->assertSame(EstadoComercial::CANCELADA, $l->fresh()->estado_comercial);
        $this->actingAs($aluno)->get('/')->assertStatus(503);
        $this->assertSame(1, $this->eventos($l, 'cancelada')->count());

        foreach ([
            fn () => app(ReactivarLicencaAction::class)->executar($l, 'x', 2),
            fn () => app(RenovarLicencaAction::class)->executar($l, new RenovarLicencaDTO, 'x', 2),
            fn () => app(EstenderToleranciaAction::class)->executar($l, 30, 'x', 2),
            fn () => app(SuspenderLicencaAction::class)->executar($l, 'x', 2),
            fn () => app(CancelarLicencaAction::class)->executar($l, 'x', 2),
        ] as $tentativa) {
            try {
                $tentativa();
                $this->fail('Devia recusar');
            } catch (DadosDeLicencaInvalidos $e) {
                $this->assertArrayHasKey('estado', $e->erros);
            }
        }
        $this->assertSame(EstadoComercial::CANCELADA, $l->fresh()->estado_comercial);
        $this->assertSame(1, EventoDeLicenca::query()->where('licenca_id', $l->id)->where('tipo', '!=', 'emitida')->count());
    }

    public function test_cancelar_a_partir_de_suspensa_e_permitido(): void
    {
        $l = $this->licenca();
        app(SuspenderLicencaAction::class)->executar($l, 'a', 1);
        app(CancelarLicencaAction::class)->executar($l, 'b', 1);

        $this->assertSame(EstadoComercial::CANCELADA, $l->fresh()->estado_comercial);
    }

    public function test_transicoes_invalidas_sao_recusadas(): void
    {
        $l = $this->licenca();

        // Reactivar uma activa; renovar/estender uma suspensa; suspender uma suspensa.
        $this->assertRecusa(fn () => app(ReactivarLicencaAction::class)->executar($l, 'x', 1));
        app(SuspenderLicencaAction::class)->executar($l, 'x', 1);
        $this->assertRecusa(fn () => app(SuspenderLicencaAction::class)->executar($l, 'x', 1));
        $this->assertRecusa(fn () => app(RenovarLicencaAction::class)->executar($l, new RenovarLicencaDTO, 'x', 1));
        $this->assertRecusa(fn () => app(EstenderToleranciaAction::class)->executar($l, 30, 'x', 1));

        // O objecto recebido pode estar desactualizado: o estado lido com bloqueio é que conta.
        $desactualizada = $l->replicate();
        $desactualizada->id = $l->id;
        $desactualizada->estado_comercial = EstadoComercial::ACTIVA;
        $this->assertRecusa(fn () => app(SuspenderLicencaAction::class)->executar($desactualizada, 'x', 1));

        $this->assertSame(1, EventoDeLicenca::query()->where('licenca_id', $l->id)->where('tipo', '!=', 'emitida')->count());
    }

    private function assertRecusa(callable $f): void
    {
        try {
            $f();
            $this->fail('Devia recusar a transição');
        } catch (DadosDeLicencaInvalidos $e) {
            $this->assertArrayHasKey('estado', $e->erros);
        }
    }

    public function test_motivo_vazio_e_recusado(): void
    {
        $l = $this->licenca();

        foreach (['', '   ', str_repeat('a', 256)] as $motivo) {
            foreach ([
                fn () => app(SuspenderLicencaAction::class)->executar($l, $motivo, 1),
                fn () => app(CancelarLicencaAction::class)->executar($l, $motivo, 1),
                fn () => app(RenovarLicencaAction::class)->executar($l, new RenovarLicencaDTO, $motivo, 1),
                fn () => app(EstenderToleranciaAction::class)->executar($l, 30, $motivo, 1),
            ] as $f) {
                try {
                    $f();
                    $this->fail('Devia recusar o motivo');
                } catch (DadosDeLicencaInvalidos $e) {
                    $this->assertArrayHasKey('motivo', $e->erros);
                }
            }
        }
        $this->assertSame(1, EventoDeLicenca::query()->count()); // só o 'emitida'
        $this->assertSame(EstadoComercial::ACTIVA, $l->fresh()->estado_comercial);
    }

    public function test_historico_ordenado_por_data(): void
    {
        $l = $this->licenca();
        app(SuspenderLicencaAction::class)->executar($l, 'a', 1);
        app(ReactivarLicencaAction::class)->executar($l, 'b', 1); // mesmo segundo: o id desempata
        $this->avancar('2026-06-03T00:00:00+00:00');
        app(SuspenderLicencaAction::class)->executar($l, 'c', 1);

        $this->assertSame(['emitida', 'suspensa', 'reactivada', 'suspensa'], $l->eventos()->pluck('tipo')->all());
    }

    public function test_sem_chave_privada_nao_renova_mas_suspende(): void
    {
        $l = $this->licenca();
        config(['licenciamento.emissor.chave_privada_ficheiro' => '']);

        foreach ([
            fn () => app(RenovarLicencaAction::class)->executar($l, new RenovarLicencaDTO, 'x', 1),
            fn () => app(EstenderToleranciaAction::class)->executar($l, 30, 'x', 1),
        ] as $f) {
            try {
                $f();
                $this->fail('Devia lançar EmissorIndisponivel');
            } catch (EmissorIndisponivel) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, LicencaEmitida::query()->count());
        $this->assertSame(EstadoComercial::ACTIVA, $l->fresh()->estado_comercial);

        app(SuspenderLicencaAction::class)->executar($l, 'Sem chave', 1);
        app(ReactivarLicencaAction::class)->executar($l, 'Sem chave', 1);
        app(CancelarLicencaAction::class)->executar($l, 'Sem chave', 1);
        $this->assertSame(EstadoComercial::CANCELADA, $l->fresh()->estado_comercial);
    }

    public function test_a_chave_e_o_token_nunca_aparecem_no_evento(): void
    {
        $l = $this->licenca();
        $nova = app(EstenderToleranciaAction::class)->executar($l, 30, 'Cortesia', 1);
        app(SuspenderLicencaAction::class)->executar($nova, 'x', 1);
        app(CancelarLicencaAction::class)->executar($nova, 'y', 1);

        $texto = json_encode(EventoDeLicenca::query()->get()->toArray());
        foreach ([$l->token, $nova->token, base64_encode($this->chavePrivadaDeTeste), bin2hex($this->chavePrivadaDeTeste)] as $segredo) {
            $this->assertStringNotContainsString($segredo, $texto);
        }
    }

    public function test_licenca_offline_suspende_so_no_registo_do_emissor(): void
    {
        $plano = Plano::query()->first() ?? app(CriarPlanoAction::class)->executar(new PlanoDTO(
            nome: 'Local', modulos: ['financeiro'], validadeDias: 365, modalidade: Modalidade::LOCAL,
        ));
        $l = app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(planoId: $plano->id, modalidade: Modalidade::LOCAL, instalacao: 'INST-ABC'), 1);

        app(SuspenderLicencaAction::class)->executar($l, 'Offline', 1);

        $this->assertSame(EstadoComercial::SUSPENSA, $l->fresh()->estado_comercial);
        $this->assertSame(0, DireitosLocais::query()->count());
    }

    public function test_renovar_e_estender_com_relogio_real_em_segundo_fraccionado(): void
    {
        $this->congelarRelogioDeTeste('2026-06-02T00:00:00.300+00:00');
        $a = $this->licenca();
        $this->congelarRelogioDeTeste('2026-06-02T00:00:00.800+00:00');
        $b = app(RenovarLicencaAction::class)->executar($a, new RenovarLicencaDTO, 'Renovação', 1);
        $c = app(EstenderToleranciaAction::class)->executar($b, 30, 'Cortesia', 1);

        $this->assertTrue($b->emitida_em->gt($a->emitida_em));
        $this->assertTrue($c->emitida_em->gt($b->emitida_em));
        $this->assertTrue($this->direitos($c)->emitidaEm->gt($this->direitos($b)->emitidaEm));
        $this->assertSame($c->id, DireitosLocais::query()->where('tenant_id', $this->tenant->id)->firstOrFail()->licenca_id);
    }

    public function test_licenca_substituida_recusa_todas_as_transicoes(): void
    {
        $antiga = $this->licenca();
        $this->avancar('2026-06-02T00:00:00+00:00');
        app(RenovarLicencaAction::class)->executar($antiga, new RenovarLicencaDTO, 'Renovação', 1);

        foreach ([
            fn () => app(RenovarLicencaAction::class)->executar($antiga, new RenovarLicencaDTO, 'x', 1),
            fn () => app(EstenderToleranciaAction::class)->executar($antiga, 30, 'x', 1),
            fn () => app(SuspenderLicencaAction::class)->executar($antiga, 'x', 1),
            fn () => app(ReactivarLicencaAction::class)->executar($antiga, 'x', 1),
            fn () => app(CancelarLicencaAction::class)->executar($antiga, 'x', 1), // cancelar também é recusado: a licença em vigor é a nova
        ] as $f) {
            try {
                $f();
                $this->fail('Devia recusar');
            } catch (DadosDeLicencaInvalidos $e) {
                $this->assertStringContainsString('substituída', $e->erros['estado']);
            }
        }
        $this->assertSame(EstadoComercial::SUBSTITUIDA, $antiga->fresh()->estado_comercial);
        $this->assertSame('Substituída', $antiga->fresh()->estado_comercial_descricao);
    }
}
