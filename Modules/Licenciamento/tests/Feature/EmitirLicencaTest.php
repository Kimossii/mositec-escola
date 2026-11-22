<?php

namespace Modules\Licenciamento\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\Licenciamento\Actions\AtualizarPlanoAction;
use Modules\Licenciamento\Actions\CriarPlanoAction;
use Modules\Licenciamento\Actions\EmitirLicencaAction;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Licenciamento\Actions\RenovarLicencaAction;
use Modules\Licenciamento\Actions\SuspenderLicencaAction;
use Modules\Licenciamento\Actions\CancelarLicencaAction;
use Modules\Licenciamento\Actions\EstenderToleranciaAction;
use Modules\Licenciamento\DTOs\RenovarLicencaDTO;
use Modules\Licenciamento\Assinatura\CodecoDeLicenca;
use Modules\Licenciamento\Documento\DireitosDaLicenca;
use Modules\Licenciamento\DTOs\EmitirLicencaDTO;
use Modules\Licenciamento\DTOs\PlanoDTO;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Exceptions\EmissorIndisponivel;
use Modules\Licenciamento\Exceptions\LicencaRecusada;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Models\EventoDeLicenca;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\ChavesPublicas;
use Modules\Licenciamento\Services\EmissorDeLicencas;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmitirLicencaTest extends TestCase
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
            'nome' => 'Básico',
            'modulos' => ['financeiro'],
            'validadeDias' => 365,
            'modalidade' => Modalidade::ONLINE,
            'limites' => ['alunos' => 200, 'utilizadores' => 10],
            'toleranciaDias' => 14,
            'avisoDias' => 20,
        ], $sobrepor)));
    }

    private function emitir(Plano $plano, array $sobrepor = [], ?int $autor = null): LicencaEmitida
    {
        return app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(...array_merge([
            'planoId' => $plano->id,
            ...($plano->modalidade === Modalidade::ONLINE
                ? ['escola' => $this->tenant->codigo]
                : ['instalacao' => 'INST-ABC']),
        ], $sobrepor)), $autor);
    }

    private function direitos(LicencaEmitida $licenca): DireitosDaLicenca
    {
        $claims = app(CodecoDeLicenca::class)->verificar($licenca->token, ChavesPublicas::daConfig());

        return DireitosDaLicenca::deClaims($claims);
    }

    public function test_emite_licenca_assinada_que_o_verificador_aceita(): void
    {
        $licenca = $this->emitir($this->plano(), autor: 5);

        $d = $this->direitos($licenca);
        $this->assertSame($licenca->id, $d->id);
        $this->assertSame($this->tenant->codigo, $d->escola);
        $this->assertSame(['financeiro'], $d->modulos);
        $this->assertSame(200, $d->limites['alunos']);
        $this->assertSame(14, $d->toleranciaDias);
        $this->assertSame(20, $d->avisoDias);
        $this->assertSame(Modalidade::ONLINE, $d->modalidade);
        $this->assertTrue($d->expiraEm->equalTo(CarbonImmutable::parse('2026-06-01')->addDays(365)));
        $this->assertSame(EstadoComercial::ACTIVA, $licenca->estado_comercial);
        $this->assertSame('Activa', $licenca->estado_comercial_descricao);
        $this->assertSame('Online', $licenca->modalidade_descricao);
    }

    public function test_a_licenca_e_snapshot_do_plano(): void
    {
        $plano = $this->plano();
        $licenca = $this->emitir($plano);

        app(AtualizarPlanoAction::class)->executar($plano, new PlanoDTO(
            nome: 'Novo', modulos: ['avaliacao'], validadeDias: 30, modalidade: Modalidade::LOCAL, limites: ['alunos' => 1],
        ));

        $licenca = $licenca->fresh();
        $this->assertSame(200, $licenca->limite_alunos);
        $this->assertSame(['financeiro'], $licenca->modulos);
        $this->assertSame(14, $licenca->tolerancia_dias);
    }

    public function test_sobreposicao_por_licenca_prevalece(): void
    {
        $licenca = $this->emitir($this->plano(), [
            'limites' => ['alunos' => 50, 'utilizadores' => null],
            'toleranciaDias' => 3,
            'avisoDias' => 5,
            'modulos' => ['avaliacao'],
            'expiraEm' => CarbonImmutable::parse('2026-12-31'),
        ]);

        $d = $this->direitos($licenca);
        $this->assertSame(50, $d->limites['alunos']);
        $this->assertNull($d->limites['utilizadores']); // null explícito = sem limite
        $this->assertSame(3, $d->toleranciaDias);
        $this->assertSame(5, $d->avisoDias);
        $this->assertSame(['avaliacao'], $d->modulos);
        $this->assertTrue($d->expiraEm->equalTo(CarbonImmutable::parse('2026-12-31')));
    }

    public function test_tolerancia_zero_e_aceite(): void
    {
        $this->assertSame(0, $this->direitos($this->emitir($this->plano(), ['toleranciaDias' => 0]))->toleranciaDias);
        $this->congelarRelogioDeTeste('2026-06-02T00:00:00+00:00'); // a reinstalação exige uma licença mais recente
        $this->assertSame(0, $this->direitos($this->emitir($this->plano(['toleranciaDias' => 0])))->toleranciaDias);
    }

    public function test_tolerancia_e_aviso_por_omissao_vem_da_configuracao(): void
    {
        config(['licenciamento.tolerancia_dias_padrao' => 9, 'licenciamento.aviso_dias_padrao' => 12]);
        $d = $this->direitos($this->emitir($this->plano(['toleranciaDias' => null, 'avisoDias' => null])));

        $this->assertSame(9, $d->toleranciaDias);
        $this->assertSame(12, $d->avisoDias);
    }

    public function test_sem_chave_privada_nao_emite(): void
    {
        config(['licenciamento.emissor.chave_privada_ficheiro' => '']);
        $plano = $this->plano();

        $this->expectException(EmissorIndisponivel::class);
        try {
            $this->emitir($plano);
        } finally {
            $this->assertSame(0, LicencaEmitida::query()->count());
        }
    }

    public function test_chave_privada_invalida_nao_emite(): void
    {
        $this->prepararEmissor(base64_encode('curta'));
        $plano = $this->plano();

        $this->expectException(EmissorIndisponivel::class);
        $this->emitir($plano);
    }

    public function test_no_saas_fica_activa_na_hora_via_instalar_licenca(): void
    {
        $plano = $this->plano();

        $licenca = $this->emitir($plano, ['escola' => $this->tenant->codigo]);

        $linha = DireitosLocais::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame($licenca->id, $linha->licenca_id);
        $this->assertSame($this->tenant->id, $licenca->tenant_local_id);
    }

    public function test_online_para_escola_desconhecida_e_recusado(): void
    {
        try {
            $this->emitir($this->plano(), ['escola' => 'ESC-DESCONHECIDA']);
            $this->fail('Devia recusar');
        } catch (DadosDeLicencaInvalidos $e) {
            $this->assertArrayHasKey('escola', $e->erros);
        }
        $this->assertSame(0, LicencaEmitida::query()->count());
    }

    public function test_codigo_da_escola_em_minusculas_ou_com_espacos_emite_e_instala(): void
    {
        $licenca = $this->emitir($this->plano(), ['escola' => '  '.strtolower($this->tenant->codigo).' ']);

        $this->assertSame($this->tenant->codigo, $licenca->escola);
        $this->assertSame($this->tenant->codigo, $this->direitos($licenca)->escola);
        $this->assertSame($licenca->id, DireitosLocais::query()->where('tenant_id', $this->tenant->id)->firstOrFail()->licenca_id);
    }

    public function test_instalacao_recusada_reverte_emissao_e_evento(): void
    {
        // O instalador recusa (ex.: assinatura não reconhecida); o emissor em si continua operacional.
        $this->mock(InstalarLicencaAction::class)->shouldReceive('executar')->andThrow(LicencaRecusada::porque('A licença não é válida.'));
        $plano = $this->plano();

        try {
            $this->emitir($plano);
            $this->fail('Devia recusar a instalação');
        } catch (LicencaRecusada) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, LicencaEmitida::query()->count());
        $this->assertSame(0, EventoDeLicenca::query()->count());
        $this->assertSame(0, DireitosLocais::query()->count());
    }

    public function test_ligacao_a_instalacao_obrigatoria_por_omissao_no_self_hosted(): void
    {
        $plano = $this->plano(['modalidade' => Modalidade::LOCAL]);

        try {
            $this->emitir($plano, ['escola' => null, 'instalacao' => null]);
            $this->fail('Devia exigir a instalação');
        } catch (DadosDeLicencaInvalidos $e) {
            $this->assertArrayHasKey('instalacao', $e->erros);
        }
        $this->assertSame(0, LicencaEmitida::query()->count());

        $licenca = $this->emitir($plano, ['escola' => null, 'instalacao' => 'INST-ABC']);
        $this->assertSame('INST-ABC', $this->direitos($licenca)->instalacao);
        $this->assertTrue($licenca->ligacao_obrigatoria);
    }

    public function test_ligacao_a_instalacao_desligavel_por_licenca(): void
    {
        $plano = $this->plano(['modalidade' => Modalidade::LOCAL]);

        $licenca = $this->emitir($plano, ['escola' => null, 'instalacao' => 'INST-ABC', 'ligacaoObrigatoria' => false]);

        $this->assertNull($this->direitos($licenca)->instalacao);
        $this->assertFalse($licenca->ligacao_obrigatoria);
    }

    public function test_online_exige_escola(): void
    {
        $this->expectException(DadosDeLicencaInvalidos::class);
        $this->emitir($this->plano(), ['escola' => null]);
    }

    public function test_evento_emitida_registado_com_autor(): void
    {
        $licenca = $this->emitir($this->plano(), autor: 7);

        $evento = EventoDeLicenca::query()->where('licenca_id', $licenca->id)->firstOrFail();
        $this->assertSame('emitida', $evento->tipo);
        $this->assertSame(7, $evento->super_admin_id);
        $this->assertSame($this->tenant->codigo, $evento->detalhe['escola']);
    }

    public function test_eventos_sao_append_only(): void
    {
        $licenca = $this->emitir($this->plano());
        $evento = EventoDeLicenca::query()->where('licenca_id', $licenca->id)->firstOrFail();

        try {
            $evento->update(['tipo' => 'outro']);
            $this->fail('update devia lançar');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(LogicException::class);
        $evento->delete();
    }

    public function test_a_chave_privada_nunca_aparece_no_evento_nem_no_log(): void
    {
        $privada = base64_encode($this->chavePrivadaDeTeste);

        $mensagens = [];
        Log::listen(function ($m) use (&$mensagens) {
            $mensagens[] = $m->message.json_encode($m->context);
        });

        $licenca = $this->emitir($this->plano(), ['escola' => $this->tenant->codigo]);

        $evento = json_encode(EventoDeLicenca::query()->where('licenca_id', $licenca->id)->firstOrFail()->toArray());
        foreach ([$evento, ...$mensagens] as $texto) {
            $this->assertStringNotContainsString($privada, $texto);
            $this->assertStringNotContainsString(bin2hex($this->chavePrivadaDeTeste), $texto);
            $this->assertStringNotContainsString($licenca->token, $texto);
        }
        $this->assertArrayNotHasKey('token', $licenca->toArray());
    }

    // --- C1: só o ONLINE se instala num tenant local ------------------------------------------

    /** @return array<string,Modalidade> */
    public static function modalidadesNaoOnline(): array
    {
        return ['local' => [Modalidade::LOCAL], 'hibrido' => [Modalidade::HIBRIDO]];
    }

    #[DataProvider('modalidadesNaoOnline')]
    public function test_licenca_local_ou_hibrida_com_codigo_igual_ao_de_um_tenant_local_nao_lhe_toca(Modalidade $modalidade): void
    {
        $plano = $this->plano(['modalidade' => $modalidade]);
        $saas = $this->emitir($this->plano(), ['escola' => $this->tenant->codigo]); // licença SaaS da escola local
        $this->congelarRelogioDeTeste('2026-06-02T00:00:00+00:00');

        // O cliente self-hosted tem o MESMO código (sequencial por instalação).
        $cliente = $this->emitir($plano, ['escola' => $this->tenant->codigo, 'instalacao' => 'INST-ABC']);

        $this->assertNull($cliente->tenant_local_id);
        $this->assertSame($this->tenant->codigo, $cliente->escola);
        $linha = DireitosLocais::query()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame($saas->id, $linha->licenca_id);
        $this->assertSame(1, DireitosLocais::query()->count());

        // Suspender/cancelar a do cliente não afecta a licença da escola local.
        app(SuspenderLicencaAction::class)->executar($cliente, 'Falta de pagamento', 1);
        $this->assertNull($linha->fresh()->revogada_em);
        app(CancelarLicencaAction::class)->executar($cliente->fresh(), 'Fim do contrato', 1);
        $this->assertNull($linha->fresh()->revogada_em);
        $this->assertSame(EstadoComercial::ACTIVA, $saas->fresh()->estado_comercial);
    }

    public function test_licenca_local_com_ligacao_a_instalacao_nao_falha_por_colisao_de_codigo(): void
    {
        $cliente = $this->emitir($this->plano(['modalidade' => Modalidade::LOCAL]), ['escola' => $this->tenant->codigo, 'instalacao' => 'INST-ABC']);

        $this->assertSame('INST-ABC', $this->direitos($cliente)->instalacao);
        $this->assertSame(0, DireitosLocais::query()->count());
    }

    // --- I4: a licença nova substitui as anteriores do mesmo alvo ---------------------------------

    public function test_segunda_emissao_para_a_mesma_escola_deixa_uma_so_activa(): void
    {
        $plano = $this->plano();
        $primeira = $this->emitir($plano);
        $this->congelarRelogioDeTeste('2026-06-02T00:00:00+00:00');
        $segunda = $this->emitir($plano);

        $this->assertSame(EstadoComercial::SUBSTITUIDA, $primeira->fresh()->estado_comercial);
        $this->assertSame(EstadoComercial::ACTIVA, $segunda->fresh()->estado_comercial);
        $this->assertSame(1, LicencaEmitida::query()->where('estado_comercial', EstadoComercial::ACTIVA->value)->count());
        $evento = EventoDeLicenca::query()->where('licenca_id', $primeira->id)->where('tipo', 'substituida')->sole();
        $this->assertSame($segunda->id, $evento->detalhe['nova_licenca_id']);
        $this->assertSame($segunda->id, DireitosLocais::query()->where('tenant_id', $this->tenant->id)->sole()->licenca_id);
    }

    public function test_emissao_para_o_mesmo_destino_tambem_substitui_a_suspensa_e_so_o_mesmo_destino(): void
    {
        $plano = $this->plano(['modalidade' => Modalidade::LOCAL]);
        $a = $this->emitir($plano, ['instalacao' => 'INST-A']);
        $outra = $this->emitir($plano, ['instalacao' => 'INST-B']);
        app(SuspenderLicencaAction::class)->executar($a, 'Atraso', 1);
        $this->congelarRelogioDeTeste('2026-06-02T00:00:00+00:00');
        $nova = $this->emitir($plano, ['instalacao' => 'INST-A']);

        $this->assertSame(EstadoComercial::SUBSTITUIDA, $a->fresh()->estado_comercial);
        $this->assertSame(EstadoComercial::ACTIVA, $outra->fresh()->estado_comercial);
        $this->assertSame(EstadoComercial::ACTIVA, $nova->fresh()->estado_comercial);
    }

    public function test_licenca_substituida_ja_nao_se_renova(): void
    {
        $plano = $this->plano();
        $velha = $this->emitir($plano);
        $this->congelarRelogioDeTeste('2026-06-02T00:00:00+00:00');
        $this->emitir($plano);

        try {
            app(RenovarLicencaAction::class)->executar($velha, new RenovarLicencaDTO, 'Tentativa', 1);
            $this->fail('Devia recusar');
        } catch (DadosDeLicencaInvalidos $e) {
            $this->assertStringContainsString('substituída', $e->erros['estado']);
        }
    }

    public function test_renovar_e_estender_continuam_a_gerar_um_so_par_de_eventos(): void
    {
        $plano = $this->plano();
        $a = $this->emitir($plano);
        $this->congelarRelogioDeTeste('2026-06-02T00:00:00+00:00');
        $b = app(RenovarLicencaAction::class)->executar($a, new RenovarLicencaDTO, 'Renovação', 1);
        $this->congelarRelogioDeTeste('2026-06-03T00:00:00+00:00');
        $c = app(EstenderToleranciaAction::class)->executar($b, 30, 'Cortesia', 1);

        $this->assertSame(0, EventoDeLicenca::query()->where('tipo', 'substituida')->count());
        $this->assertSame(1, EventoDeLicenca::query()->where('licenca_id', $a->id)->where('tipo', 'renovada')->count());
        $this->assertSame(1, EventoDeLicenca::query()->where('licenca_id', $b->id)->count() - 1); // emitida + 1 evento de extensão
        $this->assertSame(1, LicencaEmitida::query()->where('estado_comercial', EstadoComercial::ACTIVA->value)->count());
        $this->assertSame(EstadoComercial::ACTIVA, $c->fresh()->estado_comercial);
    }

    // --- I6: chave privada num ficheiro, com auto-verificação ------------------------------------

    public function test_emissor_ficheiro_em_falta_e_indisponivel(): void
    {
        config(['licenciamento.emissor.chave_privada_ficheiro' => sys_get_temp_dir().'/nao-existe-'.uniqid()]);

        $emissor = app(EmissorDeLicencas::class);
        $this->assertFalse($emissor->disponivel());
        $this->assertNotNull($emissor->motivoIndisponivel());
    }

    public function test_emissor_ficheiro_com_comprimento_errado_e_indisponivel(): void
    {
        $this->prepararEmissor(base64_encode('curta'));

        $this->assertFalse(app(EmissorDeLicencas::class)->disponivel());
    }

    public function test_emissor_ficheiro_valido_e_disponivel_e_aceita_quebra_de_linha_final(): void
    {
        $this->prepararEmissor(base64_encode($this->chavePrivadaDeTeste)."\n");

        $emissor = app(EmissorDeLicencas::class);
        $this->assertTrue($emissor->disponivel());
        $this->assertNull($emissor->motivoIndisponivel());
        $this->assertSame($this->chavePrivadaDeTeste, $emissor->chavePrivada());
    }

    public function test_emissor_chave_que_nao_corresponde_a_publica_do_kid_e_indisponivel(): void
    {
        $outra = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->prepararEmissor(base64_encode($outra));

        $emissor = app(EmissorDeLicencas::class);
        $this->assertFalse($emissor->disponivel());
        $this->assertStringContainsString('não corresponde', (string) $emissor->motivoIndisponivel());
        $this->expectException(EmissorIndisponivel::class);
        $this->emitir($this->plano());
    }

    public function test_emissor_sem_chave_publica_para_o_kid_e_indisponivel(): void
    {
        config(['licenciamento.emissor.kid' => 'outro-kid']);

        $this->assertFalse(app(EmissorDeLicencas::class)->disponivel());
    }
}
