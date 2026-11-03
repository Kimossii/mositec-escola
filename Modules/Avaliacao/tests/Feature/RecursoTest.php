<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\AnularNotaAction;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\DefinirPrazoAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\LancarExameAction;
use Modules\Avaliacao\Actions\LancarRecursoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoDecisao;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoPautaLinha;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Services\ResultadoDisciplinaService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class RecursoTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private InscricaoDisciplina $inscricao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-15 10:00:00');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
        $this->preparar('PRI_6', '5');
    }

    private function preparar(string $classe, string $minima, string $marca = 'R'): void
    {
        $this->c = $this->cenarioDeAvaliacao($classe, $marca);
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($this->c['ano']->id, $this->c['nivel']->id, 10, $minima));
        $this->inscricao = $this->inscreverAluno($this->c, 'Ana');
    }

    private function mfd(string $nota): void
    {
        $this->notasCompletas($this->c, $this->inscricao, [1 => [$nota, $nota], 2 => [$nota, $nota], 3 => [$nota, $nota]]);
    }

    private function exame(string $v): void
    {
        app(LancarExameAction::class)->executar($this->c['turma']->id, $this->c['disciplina']->id, $this->inscricao->id, $v);
    }

    private function recurso(string $v): AvaliacaoNota
    {
        return app(LancarRecursoAction::class)->executar($this->c['turma']->id, $this->c['disciplina']->id, $this->inscricao->id, $v);
    }

    private function fecharORecurso(): void
    {
        app(DefinirPrazoAction::class)->executar(AvaliacaoRegra::firstOrFail()->id, $this->c['periodos'][3]->id, TipoInstrumento::RECURSO, '2026-03-01');
    }

    private function pauta(): array
    {
        return app(PautaFinalService::class)->paraTurma($this->c['turma']->id);
    }

    private function celula(): array
    {
        return $this->pauta()['alunos'][0]['celulas'][$this->c['disciplina']->id];
    }

    public function test_disciplina_abaixo_do_minimo_espera_o_recurso_e_bloqueia_a_aprovacao(): void
    {
        $this->mfd('8');
        $this->exame('2'); // MF = 4,4 < 5

        $r = app(ResultadoDisciplinaService::class)->calcular($this->inscricao->id);
        $p = $this->pauta();

        $this->assertTrue($r->recursoNecessario);
        $this->assertTrue($r->recursoEmFalta);
        $this->assertNull($r->notaFinalArredondada());
        $this->assertNull($p['alunos'][0]['decisao']);
        $this->assertFalse($p['pode_aprovar']);
        $this->assertStringContainsString('exame de recurso em 1 aluno', implode(' ', $p['bloqueios']));
    }

    public function test_quem_esta_acima_do_minimo_nao_faz_recurso(): void
    {
        $this->mfd('8');
        $this->exame('10'); // MF = 9,2

        $this->assertFalse(app(ResultadoDisciplinaService::class)->calcular($this->inscricao->id)->recursoNecessario);

        $this->expectException(ValidationException::class);
        $this->recurso('9');
    }

    public function test_mfr_e_40_por_cento_da_mf_mais_60_por_cento_do_recurso_e_pode_salvar(): void
    {
        $this->mfd('8');
        $this->exame('2'); // MF = 4,4
        $this->recurso('8'); // MFR = 4,4×0,4 + 8×0,6 = 6,56

        $c = $this->celula();

        $this->assertSame('6.56', $c['mfr']);
        $this->assertSame('6.56', $c['nota_final']);
        $this->assertSame('8.00', $c['ner']);
        $this->assertTrue($c['aprovado']);
        $this->assertSame('Apto', $this->pauta()['alunos'][0]['decisao']['rotulo']);
        $this->assertTrue($this->pauta()['pode_aprovar']);
    }

    public function test_o_mfr_decide_mesmo_quando_e_mais_baixo_que_a_mf(): void
    {
        $this->mfd('9');
        $this->exame('1'); // MF = 9×0,4 + 1×0,6 = 4,2
        $this->recurso('0'); // MFR = 4,2×0,4 = 1,68, abaixo da MF

        $c = $this->celula();

        $this->assertSame('4.20', $c['mf']);
        $this->assertSame('1.68', $c['mfr']);
        $this->assertSame('1.68', $c['nota_final']);
        $this->assertSame('Não apto', $this->pauta()['alunos'][0]['decisao']['rotulo']);
    }

    public function test_depois_do_prazo_sem_recurso_a_disciplina_conta_como_nao_realizada(): void
    {
        $this->mfd('8');
        $this->exame('2'); // MF = 4,4
        $this->fecharORecurso();

        $r = app(ResultadoDisciplinaService::class)->calcular($this->inscricao->id);
        $p = $this->pauta();

        $this->assertTrue($r->recursoNecessario);
        $this->assertFalse($r->recursoEmFalta);
        $this->assertSame('4.40', $r->notaFinalArredondada());
        $this->assertSame('Não apto', $p['alunos'][0]['decisao']['rotulo']);
        $this->assertTrue($p['pode_aprovar']);
    }

    public function test_recurso_anulado_volta_a_pendente_e_a_contar_como_zero_entra(): void
    {
        $this->mfd('8');
        $this->exame('2');
        $nota = $this->recurso('8');

        app(AnularNotaAction::class)->executar($nota->id, false, 'Prova repetida');
        $this->assertNull($this->celula()['mfr']);
        $this->assertNull($this->pauta()['alunos'][0]['decisao']);

        $nota->refresh()->update(['estado' => \Modules\Avaliacao\Enums\EstadoNota::ANULADA_CONTA_ZERO]);
        $this->assertSame('1.76', $this->celula()['mfr']); // 4,4×0,4 + 0×0,6
    }

    public function test_o_recurso_cria_se_na_primeira_nota_no_3_trimestre_e_so_ha_um(): void
    {
        $this->mfd('8');
        $this->exame('2');
        $this->recurso('8');
        $this->recurso('9');

        $recurso = AvaliacaoInstrumento::where('tipo', TipoInstrumento::RECURSO->value)->get();
        $this->assertCount(1, $recurso);
        $this->assertSame($this->c['periodos'][3]->id, $recurso[0]->periodo_id);
        $this->assertSame('9.00', AvaliacaoNota::firstWhere('instrumento_id', $recurso[0]->id)->valor);
    }

    public function test_sem_permissao_nao_lanca_e_nao_deixa_recurso_criado(): void
    {
        $this->mfd('8');
        $this->exame('2');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com'));

        try {
            $this->recurso('8');
            $this->fail('Esperava AuthorizationException.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(0, AvaliacaoInstrumento::where('tipo', TipoInstrumento::RECURSO->value)->count());
    }

    public function test_a_7_classe_tem_recurso_sem_exame_proprio_e_usa_transita(): void
    {
        $this->preparar('SEC_7', '5', 'S');
        $this->mfd('4'); // MFD = 4 (sem exame próprio) < 5
        $this->recurso('8'); // MFR = 4×0,4 + 8×0,6 = 6,4

        $c = $this->celula();

        $this->assertNull($c['ne']);
        $this->assertSame('6.40', $c['mfr']);
        $this->assertSame('Transita', $this->pauta()['alunos'][0]['decisao']['rotulo']);
        $this->assertTrue($this->pauta()['com_recurso']);
        $this->assertFalse($this->pauta()['com_exame']);
    }

    public function test_classe_sem_recurso_recusa_lancar_recurso(): void
    {
        $this->preparar('PRI_5', '5', 'T');
        $this->mfd('3');

        $this->expectException(ValidationException::class);
        $this->recurso('9');
    }

    public function test_aprovar_grava_ner_e_mfr_e_o_instantaneo_nao_muda(): void
    {
        $this->mfd('8');
        $this->exame('2');
        $this->recurso('8');

        app(AprovarPautaAction::class)->executar($this->c['turma']->id);

        $l = AvaliacaoPautaLinha::firstOrFail();
        $this->assertSame(['8.0000', '6.5600'], [$l->ner, $l->mfr]);
        $this->assertSame(Decisao::APTO, AvaliacaoDecisao::firstOrFail()->decisao);

        AvaliacaoNota::query()->update(['valor' => '1']);
        $c = $this->celula();
        $this->assertSame(['8.00', '6.56', '6.56'], [$c['ner'], $c['mfr'], $c['nota_final']]);
    }

    public function test_mini_pauta_mostra_o_recurso_so_a_quem_precisa_e_lanca_via_http(): void
    {
        $this->mfd('8');
        $this->exame('2');
        $acima = $this->inscreverAluno($this->c, 'Rui');
        $this->notasCompletas($this->c, $acima, [1 => ['9', '9'], 2 => ['9', '9'], 3 => ['9', '9']]);
        $url = '/avaliacao/notas?'.http_build_query(['turma' => $this->c['turma']->id, 'disciplina' => $this->c['disciplina']->id, 'periodo' => $this->c['periodos'][3]->id]);

        $this->post('/avaliacao/notas/recurso', ['turma_id' => $this->c['turma']->id, 'disciplina_id' => $this->c['disciplina']->id, 'inscricao_disciplina_id' => $this->inscricao->id, 'valor' => '8'])->assertRedirect();
        $this->post('/avaliacao/notas/recurso', ['turma_id' => $this->c['turma']->id, 'disciplina_id' => $this->c['disciplina']->id, 'inscricao_disciplina_id' => $acima->id, 'valor' => '8'])->assertSessionHasErrors('inscricao_disciplina_id');

        $this->get($url)->assertInertia(fn (Assert $p) => $p
            ->where('pauta.recurso.peso_mf', '40')
            ->has('pauta.prazos.RECURSO')
            ->where('pauta.alunos', fn ($alunos) => collect($alunos)->firstWhere('inscricao_id', $this->inscricao->id)['recurso_necessario'] === true
                && collect($alunos)->firstWhere('inscricao_id', $this->inscricao->id)['mfr'] === '6.56'
                && collect($alunos)->firstWhere('inscricao_id', $acima->id)['recurso_necessario'] === false));
    }

    public function test_configuracao_so_oferece_prazo_de_recurso_nas_classes_que_o_tem(): void
    {
        $this->preparar('PRI_5', '5', 'T');
        $this->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p
            ->where('classes', fn ($cl) => collect($cl)->firstWhere('codigo', 'PRI_6')['tem_recurso'] === true && collect($cl)->firstWhere('codigo', 'PRI_5')['tem_recurso'] === false));
    }
}
