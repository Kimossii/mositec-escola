<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\EliminarInstrumentoAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\LancarNotaAction;
use Modules\Avaliacao\Actions\ReabrirPautaAction;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\LancarNotaDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\EstadoPauta;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoDecisao;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoPauta;
use Modules\Avaliacao\Models\AvaliacaoPautaLinha;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class PautaAprovacaoTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private InscricaoDisciplina $inscricao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao();
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($this->c['ano']->id, [$this->c['nivel']->id], 10, '5'));
        $this->inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->notasCompletas($this->c, $this->inscricao, [1 => ['5.33', '7'], 2 => ['6', '7'], 3 => ['8', '9']]);
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
    }

    private function aprovar(): AvaliacaoPauta
    {
        return app(AprovarPautaAction::class)->executar($this->c['turma']->id);
    }

    private function pauta(): array
    {
        return app(PautaFinalService::class)->paraTurma($this->c['turma']->id);
    }

    public function test_aprovar_grava_o_instantaneo_e_a_decisao(): void
    {
        $pauta = $this->aprovar();

        $this->assertSame(EstadoPauta::DEFINITIVA, $pauta->estado);
        $this->assertSame(1, $pauta->versao);
        $this->assertNotNull($pauta->aprovada_em);
        $linha = AvaliacaoPautaLinha::firstOrFail();
        $this->assertSame('7.0600', $linha->mfd);
        $this->assertSame('7.0550000000', $linha->mfd_exacta);
        $this->assertTrue($linha->aprovado);
        $this->assertSame(Decisao::TRANSITA, AvaliacaoDecisao::firstOrFail()->decisao);
        $this->assertSame('definitiva', $this->pauta()['estado']);
    }

    public function test_aprovar_exige_permissao(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));

        $this->expectException(AuthorizationException::class);
        $this->aprovar();
    }

    public function test_pauta_com_pendentes_nao_se_aprova_e_nada_se_grava(): void
    {
        $outra = $this->inscreverAluno($this->c, 'Sem notas');

        try {
            $this->aprovar();
            $this->fail('Esperava ValidationException por pendentes.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Faltam resultados', $e->errors()['pauta'][0]);
        }

        $this->assertSame(0, AvaliacaoPauta::count());
        $this->assertSame(0, AvaliacaoPautaLinha::count());
        $this->assertNotNull($outra);
    }

    public function test_pauta_ja_definitiva_nao_se_aprova_outra_vez(): void
    {
        $this->aprovar();

        $this->expectException(ValidationException::class);
        $this->aprovar();
    }

    public function test_o_instantaneo_nao_muda_com_notas_ou_regra_posteriores(): void
    {
        $this->aprovar();
        AvaliacaoNota::query()->update(['valor' => '1']);
        AvaliacaoRegra::query()->update(['nota_minima' => '9.99', 'casas_decimais' => 0]);

        $celula = $this->pauta()['alunos'][0]['celulas'][$this->c['disciplina']->id];

        $this->assertSame('7.06', $celula['mfd']);
        $this->assertTrue($celula['aprovado']);
        $this->assertSame('Transita', $this->pauta()['alunos'][0]['decisao']['rotulo']);
    }

    public function test_depois_de_definitiva_as_notas_e_as_avaliacoes_ficam_bloqueadas(): void
    {
        $instrumento = AvaliacaoInstrumento::firstOrFail();
        $nota = AvaliacaoNota::firstOrFail();
        $this->aprovar();

        $verificar = function (callable $accao, string $quem) {
            try {
                $accao();
                $this->fail("{$quem} devia estar bloqueado com a pauta definitiva.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('pauta', $e->errors(), $quem);
            }
        };

        $verificar(fn () => app(LancarNotaAction::class)->executar(new LancarNotaDTO($instrumento->id, $this->inscricao->id, '9', 'x')), 'lançar');
        $verificar(fn () => $this->anularNota($nota->id, false, 'x'), 'anular');
        $verificar(fn () => app(CriarInstrumentoAction::class)->executar(new InstrumentoDTO($this->c['turma']->id, $this->c['disciplina']->id, $this->c['periodos'][1]->id, TipoInstrumento::MAC, 'Nova')), 'criar avaliação');
        $verificar(fn () => app(EliminarInstrumentoAction::class)->executar($instrumento->id), 'eliminar avaliação');
    }

    public function test_reabrir_exige_permissao_motivo_e_pauta_definitiva(): void
    {
        try {
            app(ReabrirPautaAction::class)->executar($this->c['turma']->id, 'Erro de lançamento');
            $this->fail('Sem pauta definitiva não se reabre.');
        } catch (ValidationException) {
        }

        $this->aprovar();

        try {
            app(ReabrirPautaAction::class)->executar($this->c['turma']->id, '  ');
            $this->fail('Esperava ValidationException (motivo).');
        } catch (ValidationException) {
        }

        $this->actingAs($this->professorAtribuido($this->c, 'prof@example.com'));
        $this->expectException(AuthorizationException::class);
        app(ReabrirPautaAction::class)->executar($this->c['turma']->id, 'Erro de lançamento');
    }

    public function test_reabrir_permite_corrigir_e_a_nova_aprovacao_cria_a_versao_2_sem_apagar_a_1(): void
    {
        $this->aprovar();
        $pauta = app(ReabrirPautaAction::class)->executar($this->c['turma']->id, 'Nota do 3.º trimestre mal lançada');

        $this->assertSame(EstadoPauta::REABERTA, $pauta->estado);
        $this->assertSame('Nota do 3.º trimestre mal lançada', $pauta->motivo_reabertura);
        $this->assertSame('reaberta', $this->pauta()['estado']);

        $instrumento = AvaliacaoInstrumento::where('periodo_id', $this->c['periodos'][3]->id)->where('tipo', TipoInstrumento::PT->value)->firstOrFail();
        app(LancarNotaAction::class)->executar(new LancarNotaDTO($instrumento->id, $this->inscricao->id, '10', 'Correcção após reabertura'));

        $nova = $this->aprovar();

        $this->assertSame(2, $nova->versao);
        $this->assertSame(2, AvaliacaoPautaLinha::count());
        $this->assertSame(['7.0600', '7.2200'], AvaliacaoPautaLinha::orderBy('versao')->pluck('mfd')->all());
        $this->assertSame('7.22', $this->pauta()['alunos'][0]['celulas'][$this->c['disciplina']->id]['mfd']);
    }
}
