<?php

namespace Modules\Plataforma\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Licenciamento\Actions\CriarPlanoAction;
use Modules\Licenciamento\Actions\EmitirLicencaAction;
use Modules\Licenciamento\DTOs\EmitirLicencaDTO;
use Modules\Licenciamento\DTOs\PlanoDTO;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Models\EventoDeLicenca;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

/**
 * Planos e licenças no painel: o painel só chama as Actions do Licenciamento, audita o sucesso (sem token nem chaves)
 * e, sem chave privada de emissão, mostra o estado «emissor indisponível» em vez de falhar.
 */
class LicencasPlataformaTest extends TestCase
{
    use ComLicencaDeTeste;
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private const MOTIVO = 'Cortesia por atraso no pagamento';

    private SuperAdmin $admin;

    private string $sessao;

    private Tenant $escola;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.hosts_centrais' => [self::CENTRAL]]);
        config(['session.driver' => 'database']);
        $this->prepararLicencas();
        $this->prepararEmissor();
        $this->congelarRelogioDeTeste();

        $this->admin = $this->superAdmin();
        $this->sessao = $this->entrarNoPainel($this->admin);
        $this->escola = $this->escolaDeGestao('MOSI-000301', 'Escola Alfa', 'alfa.mositec.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // --- Auxiliares --------------------------------------------------------------------------

    private function plano(array $sobrepor = []): Plano
    {
        return app(CriarPlanoAction::class)->executar(new PlanoDTO(...array_merge([
            'nome' => 'Básico',
            'modulos' => ['financeiro'],
            'validadeDias' => 365,
            'modalidade' => Modalidade::ONLINE,
            'limites' => ['alunos' => 200],
            'toleranciaDias' => 7,
            'avisoDias' => 30,
        ], $sobrepor)));
    }

    private function licencaDe(Plano $plano, array $sobrepor = []): LicencaEmitida
    {
        return app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(...array_merge([
            'planoId' => $plano->id,
            ...($plano->modalidade === Modalidade::ONLINE ? ['escola' => $this->escola->codigo] : ['instalacao' => 'INST-ABC']),
        ], $sobrepor)), $this->admin->id);
    }

    private function acao(string $metodo, string $caminho, array $dados = []): TestResponse
    {
        return $this->noPainel($metodo, $caminho, $this->sessao, $dados);
    }

    private function inertiaGet(string $caminho): TestResponse
    {
        return $this->noPainel('GET', $caminho, $this->sessao, cabecalhos: $this->cabecalhosInertia());
    }

    private function auditoria(string $accao): RegistoDeAuditoria
    {
        $registo = RegistoDeAuditoria::query()->where('accao', $accao)->latest('id')->first();
        $this->assertNotNull($registo, "Sem registo de auditoria '{$accao}'.");

        return $registo;
    }

    /** @return array<string, mixed> */
    private function dadosDoPlano(array $sobrepor = []): array
    {
        return array_merge([
            'nome' => 'Profissional',
            'descricao' => 'Para escolas médias',
            'validade_dias' => 365,
            'modalidade' => 1,
            'limite_alunos' => 500,
            'limite_utilizadores' => '',
            'tolerancia_dias' => '',
            'aviso_dias' => '',
            'modulos' => ['financeiro', 'avaliacao'],
        ], $sobrepor);
    }

    // --- Planos ------------------------------------------------------------------------------

    public function test_super_admin_cria_plano(): void
    {
        $this->acao('POST', '/plataforma/planos', $this->dadosDoPlano())
            ->assertRedirect($this->urlCentral('/plataforma/planos'));

        $plano = Plano::query()->where('nome', 'Profissional')->firstOrFail();
        $this->assertSame(500, $plano->limite_alunos);
        $this->assertNull($plano->limite_utilizadores);
        $this->assertSame(['financeiro', 'avaliacao'], $plano->modulos);
        $this->assertSame(Modalidade::ONLINE, $plano->modalidade);

        $registo = $this->auditoria('plano.criado');
        $this->assertSame($this->admin->id, $registo->super_admin_id);
        $this->assertSame($plano->id, $registo->detalhe['plano_id']);

        $lista = $this->inertiaGet('/plataforma/planos');
        $lista->assertOk();
        $this->assertSame('Plataforma/Planos/Index', $lista->json('component'));
        $this->assertSame(['Profissional'], collect($lista->json('props.planos'))->pluck('nome')->all());
    }

    public function test_plano_invalido_mostra_os_erros_da_action(): void
    {
        $this->acao('POST', '/plataforma/planos', $this->dadosDoPlano(['modulos' => ['inexistente']]))
            ->assertSessionHasErrors('modulos');

        $this->assertSame(0, Plano::query()->count());
        $this->assertSame(0, RegistoDeAuditoria::query()->where('accao', 'plano.criado')->count());
    }

    public function test_super_admin_actualiza_plano_e_fica_auditado(): void
    {
        $plano = $this->plano();

        $this->acao('PUT', "/plataforma/planos/{$plano->id}", $this->dadosDoPlano(['nome' => 'Básico Plus']))
            ->assertRedirect($this->urlCentral('/plataforma/planos'));

        $this->assertSame('Básico Plus', $plano->fresh()->nome);
        $this->assertSame($plano->id, $this->auditoria('plano.actualizado')->detalhe['plano_id']);

        $edicao = $this->inertiaGet("/plataforma/planos/{$plano->id}/editar");
        $this->assertSame('Plataforma/Planos/Form', $edicao->json('component'));
        $this->assertSame('Básico Plus', $edicao->json('props.plano.nome'));
    }

    // --- Emitir ------------------------------------------------------------------------------

    public function test_super_admin_emite_licenca_para_escola_saas_e_fica_activa(): void
    {
        $plano = $this->plano();

        $resposta = $this->acao('POST', '/plataforma/licencas', [
            'plano_id' => $plano->id,
            'escola' => $this->escola->codigo,
            'modalidade' => 1,
            'validade_dias' => 100,
            'limite_alunos' => 300,
            'limite_utilizadores' => '',
            'tolerancia_dias' => '',
            'aviso_dias' => '',
            'modulos' => ['financeiro', 'avaliacao'],
        ]);

        $licenca = LicencaEmitida::query()->firstOrFail();
        $resposta->assertRedirect($this->urlCentral("/plataforma/licencas/{$licenca->id}"));
        $this->assertSame(EstadoComercial::ACTIVA, $licenca->estado_comercial);
        $this->assertSame($this->escola->codigo, $licenca->escola);
        $this->assertSame(300, $licenca->limite_alunos);
        $this->assertNull($licenca->limite_utilizadores);
        $this->assertSame(7, $licenca->tolerancia_dias); // herdado do plano
        $this->assertSame(['financeiro', 'avaliacao'], $licenca->modulos);
        $this->assertTrue($licenca->expira_em->equalTo(CarbonImmutable::parse('2026-06-01')->addDays(100)));
        // SaaS: instalada de imediato nos direitos locais da escola.
        $this->assertSame(1, DireitosLocais::query()->where('licenca_id', $licenca->id)->count());

        $registo = $this->auditoria('licenca.emitida');
        $this->assertSame($this->escola->codigo, $registo->codigo_tenant);
        $this->assertSame($licenca->id, $registo->detalhe['licenca_id']);
        $this->assertSame($this->admin->id, $registo->super_admin_id);

        $detalhe = $this->inertiaGet("/plataforma/licencas/{$licenca->id}");
        $this->assertSame('Plataforma/Licencas/Show', $detalhe->json('component'));
        $this->assertSame('valida', $detalhe->json('props.licenca.validade'));
        $this->assertSame($licenca->token, $detalhe->json('props.licenca.token'));
        $this->assertSame('emitida', $detalhe->json('props.eventos.0.tipo'));
    }

    public function test_emite_licenca_self_hosted_com_codigo_de_instalacao_e_descarrega_ficheiro(): void
    {
        $plano = $this->plano(['modalidade' => Modalidade::LOCAL, 'nome' => 'Local']);

        $this->acao('POST', '/plataforma/licencas', [
            'plano_id' => $plano->id,
            'instalacao' => 'INST-ABC',
            'modalidade' => 2,
            'validade_dias' => 365,
            'ligacao_obrigatoria' => true,
            'modulos' => ['financeiro'],
        ])->assertSessionHasNoErrors();

        $licenca = LicencaEmitida::query()->firstOrFail();
        $this->assertSame('INST-ABC', $licenca->instalacao);
        $this->assertNull($licenca->escola);
        $this->assertTrue($licenca->ligacao_obrigatoria);
        // Sem escola desta instalação: só se emite o token (nada instalado) e a auditoria não leva tenant.
        $this->assertSame(0, DireitosLocais::query()->count());
        $this->assertNull($this->auditoria('licenca.emitida')->codigo_tenant);

        $descarga = $this->acao('GET', "/plataforma/licencas/{$licenca->id}/descarregar");
        $descarga->assertOk();
        $this->assertSame($licenca->token, $descarga->getContent());
        $descarga->assertHeader('Content-Disposition', 'attachment; filename=licenca-inst-abc-20260601.licenca');
        $this->assertStringContainsString('no-store', (string) $descarga->headers->get('Cache-Control'));
        $this->assertStringStartsWith('text/plain', (string) $descarga->headers->get('Content-Type'));
    }

    public function test_a_descarga_de_licenca_de_escola_usa_o_codigo_da_escola_no_nome(): void
    {
        $licenca = $this->licencaDe($this->plano());

        $this->acao('GET', "/plataforma/licencas/{$licenca->id}/descarregar")
            ->assertHeader('Content-Disposition', 'attachment; filename=licenca-mosi-000301-20260601.licenca');
    }

    public function test_emissao_com_dados_invalidos_mostra_erros_e_nao_grava(): void
    {
        $plano = $this->plano();

        $this->acao('POST', '/plataforma/licencas', ['plano_id' => $plano->id, 'escola' => 'MOSI-999999', 'modulos' => ['financeiro']])
            ->assertSessionHasErrors('escola');

        $this->assertSame(0, LicencaEmitida::query()->count());
        $this->assertSame(0, RegistoDeAuditoria::query()->where('accao', 'licenca.emitida')->count());
    }

    public function test_sem_chave_privada_o_painel_mostra_emissor_indisponivel(): void
    {
        $plano = $this->plano();
        config(['licenciamento.emissor.chave_privada_ficheiro' => '']);

        $pagina = $this->inertiaGet('/plataforma/licencas/emitir');
        $pagina->assertOk();
        $this->assertFalse($pagina->json('props.emissor.disponivel'));
        $this->assertNotEmpty($pagina->json('props.emissor.mensagem'));

        $lista = $this->inertiaGet('/plataforma/licencas');
        $this->assertFalse($lista->json('props.emissor.disponivel'));

        // O POST responde com a mesma mensagem (nunca 500) e não grava nada.
        $resposta = $this->acao('POST', '/plataforma/licencas', ['plano_id' => $plano->id, 'escola' => $this->escola->codigo, 'modulos' => ['financeiro']]);
        $resposta->assertSessionHasErrors(['geral' => $pagina->json('props.emissor.mensagem')]);
        $this->assertSame(0, LicencaEmitida::query()->count());
    }

    public function test_sem_chave_privada_renovar_e_estender_dao_a_mesma_mensagem_e_suspender_continua_a_funcionar(): void
    {
        $licenca = $this->licencaDe($this->plano());
        config(['licenciamento.emissor.chave_privada_ficheiro' => '']);
        $mensagem = $this->inertiaGet('/plataforma/licencas/emitir')->json('props.emissor.mensagem');

        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/renovar", ['motivo' => self::MOTIVO])
            ->assertSessionHasErrors(['geral' => $mensagem]);
        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/estender", ['motivo' => self::MOTIVO, 'tolerancia_dias' => 20])
            ->assertSessionHasErrors(['geral' => $mensagem]);
        $this->assertSame(1, LicencaEmitida::query()->count());

        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/suspender", ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();
        $this->assertSame(EstadoComercial::SUSPENSA, $licenca->fresh()->estado_comercial);
    }

    // --- Ciclo de vida -----------------------------------------------------------------------

    public function test_estender_regista_auditoria_com_motivo(): void
    {
        $licenca = $this->licencaDe($this->plano());

        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/estender", ['motivo' => self::MOTIVO, 'tolerancia_dias' => 21])
            ->assertSessionHasNoErrors();

        $nova = LicencaEmitida::query()->where('estado_comercial', EstadoComercial::ACTIVA->value)->firstOrFail();
        $this->assertNotSame($licenca->id, $nova->id);
        $this->assertSame(21, $nova->tolerancia_dias);
        $this->assertSame(EstadoComercial::SUBSTITUIDA, $licenca->fresh()->estado_comercial);

        $registo = $this->auditoria('licenca.estendida');
        $this->assertSame(self::MOTIVO, $registo->detalhe['motivo']);
        $this->assertSame(7, $registo->detalhe['tolerancia_anterior']);
        $this->assertSame(21, $registo->detalhe['tolerancia_nova']);
        $this->assertSame($nova->id, $registo->detalhe['nova_licenca_id']);
        $this->assertSame($this->escola->codigo, $registo->codigo_tenant);
        $this->assertSame($this->admin->id, $registo->super_admin_id);
    }

    public function test_renovar_regista_auditoria_e_substitui_a_anterior(): void
    {
        $licenca = $this->licencaDe($this->plano());

        $resposta = $this->acao('POST', "/plataforma/licencas/{$licenca->id}/renovar", ['motivo' => self::MOTIVO]);

        $nova = LicencaEmitida::query()->where('estado_comercial', EstadoComercial::ACTIVA->value)->firstOrFail();
        $resposta->assertRedirect($this->urlCentral("/plataforma/licencas/{$nova->id}"));
        $this->assertSame(EstadoComercial::SUBSTITUIDA, $licenca->fresh()->estado_comercial);

        $registo = $this->auditoria('licenca.renovada');
        $this->assertSame(self::MOTIVO, $registo->detalhe['motivo']);
        $this->assertSame($licenca->id, $registo->detalhe['licenca_anterior_id']);
        $this->assertSame($nova->id, $registo->detalhe['nova_licenca_id']);
    }

    public function test_suspender_reactivar_e_cancelar_registam_auditoria(): void
    {
        $licenca = $this->licencaDe($this->plano());

        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/suspender", ['motivo' => self::MOTIVO])->assertSessionHasNoErrors();
        $this->assertSame(EstadoComercial::SUSPENSA, $licenca->fresh()->estado_comercial);
        $this->assertSame(self::MOTIVO, $this->auditoria('licenca.suspensa')->detalhe['motivo']);

        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/reactivar", ['motivo' => 'Pagamento regularizado'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoComercial::ACTIVA, $licenca->fresh()->estado_comercial);
        $this->assertSame('Pagamento regularizado', $this->auditoria('licenca.reactivada')->detalhe['motivo']);

        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/cancelar", ['motivo' => 'Contrato terminado'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoComercial::CANCELADA, $licenca->fresh()->estado_comercial);
        $registo = $this->auditoria('licenca.cancelada');
        $this->assertSame('Contrato terminado', $registo->detalhe['motivo']);
        $this->assertSame($licenca->id, $registo->detalhe['licenca_id']);
        $this->assertSame($this->escola->codigo, $registo->codigo_tenant);
    }

    public function test_motivo_vazio_e_recusado_em_todas_as_accoes_do_ciclo_de_vida(): void
    {
        $licenca = $this->licencaDe($this->plano());

        foreach (['renovar', 'estender', 'suspender', 'reactivar', 'cancelar'] as $accao) {
            foreach (['', '   '] as $motivo) {
                $this->acao('POST', "/plataforma/licencas/{$licenca->id}/{$accao}", ['motivo' => $motivo, 'tolerancia_dias' => 30])
                    ->assertSessionHasErrors('motivo');
            }
        }

        $this->assertSame(EstadoComercial::ACTIVA, $licenca->fresh()->estado_comercial);
        $this->assertSame(1, LicencaEmitida::query()->count());
        $this->assertSame(0, RegistoDeAuditoria::query()->where('accao', 'like', 'licenca.%')->where('accao', '!=', 'licenca.emitida')->count());
        $this->assertSame(0, EventoDeLicenca::query()->where('tipo', '!=', 'emitida')->count());
    }

    public function test_transicao_recusada_mostra_a_mensagem_da_action_sem_auditar(): void
    {
        $licenca = $this->licencaDe($this->plano());
        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/cancelar", ['motivo' => self::MOTIVO]);

        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/suspender", ['motivo' => self::MOTIVO])
            ->assertSessionHasErrors('estado');
        $this->assertSame(0, RegistoDeAuditoria::query()->where('accao', 'licenca.suspensa')->count());
    }

    public function test_renovar_e_estender_com_dados_invalidos_devolvem_os_erros_da_action_por_chave(): void
    {
        $licenca = $this->licencaDe($this->plano());
        // O módulo da licença já não é licenciável: a re-emissão falha com a chave `modulos` (nem `motivo`, nem `estado`, nem `plano`).
        config(['licenciamento.modulos_licenciaveis' => ['avaliacao']]);

        $renovar = $this->acao('POST', "/plataforma/licencas/{$licenca->id}/renovar", ['motivo' => self::MOTIVO]);
        $renovar->assertRedirect($this->urlCentral("/plataforma/licencas/{$licenca->id}"));
        $renovar->assertSessionHasErrors('modulos');
        $renovar->assertSessionDoesntHaveErrors(['motivo', 'geral']);

        $estender = $this->acao('POST', "/plataforma/licencas/{$licenca->id}/estender", ['motivo' => self::MOTIVO, 'tolerancia_dias' => 30]);
        $estender->assertRedirect($this->urlCentral("/plataforma/licencas/{$licenca->id}"));
        $estender->assertSessionHasErrors('modulos');

        // Nada mudou nem foi auditado.
        $this->assertSame(1, LicencaEmitida::query()->count());
        $this->assertSame(EstadoComercial::ACTIVA, $licenca->fresh()->estado_comercial);
        $this->assertSame(0, RegistoDeAuditoria::query()->whereIn('accao', ['licenca.renovada', 'licenca.estendida'])->count());
    }

    public function test_licenca_inexistente_da_404(): void
    {
        $this->acao('GET', '/plataforma/licencas/nao-existe')->assertNotFound();
        $this->acao('POST', '/plataforma/licencas/nao-existe/cancelar', ['motivo' => self::MOTIVO])->assertNotFound();
    }

    // --- Segredos ----------------------------------------------------------------------------

    public function test_a_auditoria_nunca_contem_o_token(): void
    {
        $licenca = $this->licencaDe($this->plano());
        $this->acao('POST', "/plataforma/licencas/{$licenca->id}/estender", ['motivo' => self::MOTIVO, 'tolerancia_dias' => 15]);
        $nova = LicencaEmitida::query()->where('estado_comercial', EstadoComercial::ACTIVA->value)->firstOrFail();
        $this->acao('POST', "/plataforma/licencas/{$nova->id}/renovar", ['motivo' => self::MOTIVO]);
        $this->acao('POST', '/plataforma/licencas', ['plano_id' => $this->plano(['nome' => 'Outro'])->id, 'escola' => $this->escola->codigo, 'modulos' => ['financeiro']]);

        $tokens = LicencaEmitida::query()->pluck('token');
        $this->assertGreaterThanOrEqual(3, $tokens->count());

        $tudo = RegistoDeAuditoria::query()->get()->map(fn (RegistoDeAuditoria $r) => json_encode($r->detalhe))->implode("\n");
        foreach ($tokens as $token) {
            $this->assertStringNotContainsString($token, $tudo);
        }
        $this->assertStringNotContainsString(base64_encode($this->chavePrivadaDeTeste), $tudo);
        $this->assertStringNotContainsString('v4.public', $tudo);

        $eventos = EventoDeLicenca::query()->get()->map(fn (EventoDeLicenca $e) => json_encode($e->detalhe))->implode("\n");
        $this->assertStringNotContainsString('v4.public', $eventos);
    }

    public function test_a_lista_de_licencas_nunca_envia_o_token(): void
    {
        $licenca = $this->licencaDe($this->plano());

        $lista = $this->inertiaGet('/plataforma/licencas');

        $this->assertStringNotContainsString($licenca->token, (string) $lista->getContent());
    }

    // --- Listagem ----------------------------------------------------------------------------

    public function test_a_listagem_mostra_proximas_expiracoes_ordenadas(): void
    {
        $plano = $this->plano();
        $b = $this->escolaDeGestao('MOSI-000302', 'Escola Beta', 'beta.mositec.test');
        $c = $this->escolaDeGestao('MOSI-000303', 'Escola Gama', 'gama.mositec.test');

        $tarde = $this->licencaDe($plano, ['escola' => $this->escola->codigo, 'expiraEm' => CarbonImmutable::parse('2026-12-01')]);
        $cedo = $this->licencaDe($plano, ['escola' => $b->codigo, 'expiraEm' => CarbonImmutable::parse('2026-06-20')]);
        $meio = $this->licencaDe($plano, ['escola' => $c->codigo, 'expiraEm' => CarbonImmutable::parse('2026-09-01')]);
        // Não entra nas próximas expirações: já não está activa.
        $d = $this->escolaDeGestao('MOSI-000304', 'Escola Delta', 'delta.mositec.test');
        $cancelada = $this->licencaDe($plano, ['escola' => $d->codigo, 'expiraEm' => CarbonImmutable::parse('2026-06-05')]);
        $this->acao('POST', "/plataforma/licencas/{$cancelada->id}/cancelar", ['motivo' => self::MOTIVO]);

        $pagina = $this->inertiaGet('/plataforma/licencas');
        $pagina->assertOk();
        $this->assertSame('Plataforma/Licencas/Index', $pagina->json('component'));
        $this->assertSame([$cedo->id, $meio->id, $tarde->id], collect($pagina->json('props.proximas_expiracoes'))->pluck('id')->all());
        // «A expirar» é derivado das datas (aviso 30 dias): a de 20 de Junho está dentro do aviso, a de Dezembro não.
        $this->assertSame(['a_expirar', 'valida', 'valida'], collect($pagina->json('props.proximas_expiracoes'))->pluck('validade')->all());
        $this->assertSame(4, $pagina->json('props.licencas.total'));
    }

    public function test_a_validade_derivada_acompanha_as_datas_e_o_estado_comercial(): void
    {
        // Agora = 2026-06-01; aviso 5 dias e tolerância 10 dias.
        $plano = $this->plano(['toleranciaDias' => 10, 'avisoDias' => 5]);
        $inicio = CarbonImmutable::parse('2026-01-01');
        $casos = [
            'valida' => '2026-06-20',
            'a_expirar' => '2026-06-04',
            'em_tolerancia' => '2026-05-28',
            'expirada' => '2026-05-01',
        ];

        $ids = [];
        foreach (array_keys($casos) as $i => $esperada) {
            $escola = $this->escolaDeGestao('MOSI-0004' . $i, "Escola {$esperada}", "{$i}.mositec.test");
            $ids[$esperada] = $this->licencaDe($plano, ['escola' => $escola->codigo, 'inicio' => $inicio, 'expiraEm' => CarbonImmutable::parse($casos[$esperada])])->id;
        }

        foreach ($casos as $esperada => $_) {
            $this->assertSame($esperada, $this->inertiaGet("/plataforma/licencas/{$ids[$esperada]}")->json('props.licenca.validade'));
        }

        // O estado comercial tem prioridade sobre as datas quando a licença deixa de estar activa.
        $this->acao('POST', "/plataforma/licencas/{$ids['valida']}/suspender", ['motivo' => self::MOTIVO]);
        $this->assertSame('suspensa', $this->inertiaGet("/plataforma/licencas/{$ids['valida']}")->json('props.licenca.validade'));
    }

    public function test_a_escola_mostra_o_estado_da_licenca_actual(): void
    {
        $semLicenca = $this->inertiaGet("/plataforma/escolas/{$this->escola->codigo}");
        $this->assertNull($semLicenca->json('props.licenca'));

        $licenca = $this->licencaDe($this->plano());

        $com = $this->inertiaGet("/plataforma/escolas/{$this->escola->codigo}");
        $this->assertSame($licenca->id, $com->json('props.licenca.id'));
        $this->assertSame('valida', $com->json('props.licenca.validade'));
        $this->assertSame('Activa', $com->json('props.licenca.estado_comercial_descricao'));
        $this->assertStringNotContainsString($licenca->token, (string) $com->getContent());
    }

    // --- Acesso ------------------------------------------------------------------------------

    public function test_utilizador_da_escola_nao_acede_ao_painel(): void
    {
        $plano = $this->plano();
        $licenca = $this->licencaDe($plano);

        // No host de uma escola o painel não existe (404), mesmo com o cookie do painel.
        $escola = 'http://alfa.mositec.test';
        foreach (['/plataforma/licencas', '/plataforma/planos', '/plataforma/licencas/emitir', "/plataforma/licencas/{$licenca->id}", "/plataforma/licencas/{$licenca->id}/descarregar"] as $caminho) {
            $this->pedido('GET', $escola . $caminho)->assertNotFound();
        }
        $this->pedido('POST', $escola . '/plataforma/licencas', ['plano_id' => $plano->id, 'escola' => $this->escola->codigo, 'modulos' => ['financeiro']])->assertNotFound();
        $this->pedido('POST', $escola . "/plataforma/licencas/{$licenca->id}/cancelar", ['motivo' => self::MOTIVO])->assertNotFound();

        // No host central, sem a sessão do painel, vai para o login e nada muda.
        foreach (['/plataforma/licencas', '/plataforma/planos', "/plataforma/licencas/{$licenca->id}/descarregar"] as $caminho) {
            $this->pedido('GET', $this->urlCentral($caminho))->assertRedirect($this->urlCentral('/plataforma/login'));
        }
        $this->pedido('POST', $this->urlCentral("/plataforma/licencas/{$licenca->id}/cancelar"), ['motivo' => self::MOTIVO])
            ->assertRedirect($this->urlCentral('/plataforma/login'));
        $this->assertSame(EstadoComercial::ACTIVA, $licenca->fresh()->estado_comercial);
    }
}
