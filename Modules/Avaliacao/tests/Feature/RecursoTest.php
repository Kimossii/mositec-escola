<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\FasePauta;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoDecisao;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoPauta;
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
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($this->c['ano']->id, [$this->c['nivel']->id], 10, $minima));
        $this->inscricao = $this->inscreverAluno($this->c, 'Ana');
    }

    private function mfd(string $nota): void
    {
        $this->notasCompletas($this->c, $this->inscricao, [1 => [$nota, $nota], 2 => [$nota, $nota], 3 => [$nota, $nota]]);
    }

    private function exame(string $v): void
    {
        $this->lancarExame($this->c['turma']->id, $this->c['disciplina']->id, $this->inscricao->id, $v);
    }

    /** A 1.ª fase fecha-se antes do recurso: é ela que diz quem vai. */
    private function aprovarPrimeiraFase(): void
    {
        if (! app(PautaFinalService::class)->definitiva($this->c['turma']->id, FasePauta::EPOCA_NORMAL)) {
            app(AprovarPautaAction::class)->executar($this->c['turma']->id);
        }
    }

    private function recurso(string $v): AvaliacaoNota
    {
        $this->aprovarPrimeiraFase();

        return $this->lancarRecurso($this->c['turma']->id, $this->c['disciplina']->id, $this->inscricao->id, $v);
    }

    private function fecharORecurso(): void
    {
        $this->definirPrazo($this->c['periodos'][3]->id, TipoInstrumento::RECURSO, '2026-03-01');
    }

    /** Depois de a 1.ª fase estar aprovada, a pauta que interessa ao recurso é a da 2.ª fase. */
    private function pauta(): array
    {
        $fase = app(PautaFinalService::class)->definitiva($this->c['turma']->id, FasePauta::EPOCA_NORMAL) ? FasePauta::RECURSO : FasePauta::EPOCA_NORMAL;

        return app(PautaFinalService::class)->paraTurma($this->c['turma']->id, $fase);
    }

    private function celula(): array
    {
        return $this->pauta()['alunos'][0]['celulas'][$this->c['disciplina']->id];
    }

    public function test_disciplina_abaixo_do_minimo_pode_ter_recurso_mas_ele_nao_bloqueia_a_aprovacao(): void
    {
        $this->mfd('8');
        $this->exame('2'); // MF = 4,4 < 5

        $r = app(ResultadoDisciplinaService::class)->calcular($this->inscricao->id);
        $p = $this->pauta();

        $this->assertTrue($r->recursoNecessario);
        $this->assertSame('4.40', $r->notaFinalArredondada());
        $this->assertSame('Recurso', $p['alunos'][0]['decisao']['rotulo']); // a 1.ª fase manda-o ao recurso: ainda não reprovou
        $this->assertTrue($p['pode_aprovar']);
        $this->assertStringContainsString('vão ao exame de recurso', implode(' ', $p['avisos']));
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

    public function test_sem_nota_de_recurso_so_reprova_depois_do_prazo_e_a_pauta_do_recurso_aprova_se(): void
    {
        $this->mfd('8');
        $this->exame('2'); // MF = 4,4
        $this->aprovarPrimeiraFase();

        // Com o prazo do recurso aberto, falta a nota: a pauta do recurso não se aprova ainda.
        $aberta = $this->pauta();
        $this->assertFalse($aberta['pode_aprovar']);
        $this->assertNull($aberta['alunos'][0]['decisao']);
        $this->assertStringContainsString('Faltam notas de recurso', implode(' ', $aberta['bloqueios']));

        // Passado o prazo, sem NER fica com a nota que tinha, e reprova mesmo.
        $this->fecharORecurso();
        $this->assertSame('4.40', $this->celula()['nota_final']);
        $this->assertNull($this->celula()['mfr']);
        $this->assertSame('Não apto', $this->pauta()['alunos'][0]['decisao']['rotulo']);

        app(AprovarPautaAction::class)->executar($this->c['turma']->id, FasePauta::RECURSO);

        $this->assertSame(Decisao::NAO_APTO, AvaliacaoDecisao::query()->where('decisao', '!=', Decisao::RECURSO->value)->firstOrFail()->decisao);
    }

    public function test_recurso_anulado_deixa_a_nota_base_e_a_contar_como_zero_entra(): void
    {
        $this->mfd('8');
        $this->exame('2');
        $nota = $this->recurso('8');

        $this->anularNota($nota->id, false, 'Prova repetida');
        $this->assertNull($this->celula()['mfr']);
        $this->assertSame('4.40', $this->celula()['nota_final']);

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

        app(AprovarPautaAction::class)->executar($this->c['turma']->id, FasePauta::RECURSO);

        $recurso = AvaliacaoPauta::query()->where('fase', FasePauta::RECURSO->value)->firstOrFail();
        $l = AvaliacaoPautaLinha::query()->where('pauta_id', $recurso->id)->firstOrFail();
        $this->assertSame(['8.0000', '6.5600'], [$l->ner, $l->mfr]);
        $this->assertSame(Decisao::APTO, AvaliacaoDecisao::query()->where('pauta_id', $recurso->id)->firstOrFail()->decisao);

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
        $this->lancarExame($this->c['turma']->id, $this->c['disciplina']->id, $acima->id, '10');
        $this->aprovarPrimeiraFase();
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
        // A regra da 5.ª não tem recurso (e por isso o ecrã não lhe oferece prazo de recurso); a da 6.ª, do ano do cenário, tem-no.
        $this->get('/avaliacao/configuracao?ano='.$this->c['ano']->id)->assertInertia(fn (Assert $p) => $p->where('regras.0.com_recurso', false));
        $this->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p->where('regras.0.com_recurso', true));
    }

    // ---- regras da escola: máximo de negativas e disciplinas-chave

    /** Dois alunos-disciplina negativos na mesma matrícula: a 1.ª (a do cenário) e a «POR». */
    private function duasNegativas(?int $maximo, array $chaveIndices = []): array
    {
        $c2 = $this->outraDisciplina($this->c, 'POR');
        $inscricao2 = $this->inscreverNaDisciplina($this->inscricao, $c2);
        $this->mfd('8');
        $this->exame('2');
        $this->notasCompletas($c2, $inscricao2, [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
        $this->lancarExame($c2['turma']->id, $c2['disciplina']->id, $inscricao2->id, '2');
        $ids = [$this->c['disciplina']->id, $c2['disciplina']->id];
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO(
            $this->c['ano']->id, [$this->c['nivel']->id], 10, '5', recurso_max_disciplinas: $maximo, disciplinas_chave: array_map(fn (int $i) => $ids[$i], $chaveIndices),
        ));

        return [$c2, $inscricao2];
    }

    public function test_acima_do_maximo_de_negativas_e_reprovacao_directa_sem_recurso(): void
    {
        [$c2, $inscricao2] = $this->duasNegativas(1);

        $p = $this->pauta();

        $this->assertFalse($p['alunos'][0]['recurso']['elegivel']);
        $this->assertStringContainsString('2 disciplinas negativas (o máximo para recurso é 1)', $p['alunos'][0]['recurso']['texto']);
        $this->assertFalse($p['alunos'][0]['celulas'][$this->c['disciplina']->id]['recurso_permitido']);
        $this->assertStringContainsString('reprovam directamente', implode(' ', $p['avisos']));
        $this->assertTrue($p['pode_aprovar']);

        try {
            $this->recurso('8');
            $this->fail('Esperava ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('reprovação directa', $e->errors()['inscricao_disciplina_id'][0]);
        }
        $this->assertSame(0, AvaliacaoNota::whereHas('instrumento', fn ($q) => $q->where('tipo', TipoInstrumento::RECURSO->value))->count());
    }

    public function test_dentro_do_maximo_de_negativas_vai_ao_recurso(): void
    {
        $this->duasNegativas(2);

        $this->assertTrue($this->pauta()['alunos'][0]['recurso']['elegivel']);
        $this->assertSame('6.56', $this->recurso('8') ? $this->celula()['mfr'] : null);
    }

    public function test_negativa_a_disciplina_chave_e_reprovacao_directa_mas_a_outra_negativa_nao(): void
    {
        $this->duasNegativas(null, [0]); // a 1.ª disciplina é a chave

        $p = $this->pauta();

        $this->assertFalse($p['alunos'][0]['recurso']['elegivel']);
        $this->assertStringContainsString('disciplina-chave', $p['alunos'][0]['recurso']['texto']);
        $this->expectException(ValidationException::class);
        $this->recurso('8');
    }

    public function test_negativa_so_fora_das_disciplinas_chave_mantem_o_direito_ao_recurso(): void
    {
        $this->duasNegativas(null, [1]); // a chave é a «POR»…
        AvaliacaoNota::whereHas('instrumento', fn ($q) => $q->where('disciplina_id', $this->c['disciplina']->id + 1)->where('tipo', TipoInstrumento::EXAME->value))->update(['valor' => '10']); // …e passa no exame

        $p = $this->pauta();

        $this->assertTrue($p['alunos'][0]['recurso']['elegivel']);
        $this->assertTrue($p['alunos'][0]['celulas'][$this->c['disciplina']->id]['recurso_permitido']);
    }

    public function test_a_configuracao_guarda_o_maximo_e_as_disciplinas_chave(): void
    {
        $outra = $this->outraDisciplina($this->c, 'POR')['disciplina'];
        $base = ['ano_lectivo_id' => $this->c['ano']->id, 'nome' => 'Regra', 'nivel_ids' => [$this->c['nivel']->id], 'com_exame' => true, 'com_recurso' => true, 'sigla_prova' => 'PT', 'escala_maxima' => 10, 'nota_minima' => '5', 'peso_mac' => '50', 'peso_pt' => '50',
            'casas_decimais' => 2, 'modo_arredondamento' => 0, 'tratamento_negativas' => 0];

        $this->post('/avaliacao/configuracao/regras', [...$base, 'recurso_max_disciplinas' => 2, 'disciplinas_chave' => [$outra->id]])->assertRedirect();

        $this->get('/avaliacao/configuracao')->assertInertia(fn (Assert $p) => $p
            ->where('regras', fn ($rs) => collect($rs)->firstWhere('nome', 'Regra')['recurso_max_disciplinas'] === 2
                && collect($rs)->firstWhere('nome', 'Regra')['disciplinas_chave'] === [$outra->id]));

        $this->post('/avaliacao/configuracao/regras', [...$base, 'disciplinas_chave' => [999999]])->assertNotFound();
    }

    public function test_o_recurso_so_abre_depois_da_1_fase_e_a_pauta_do_recurso_so_tem_quem_la_foi(): void
    {
        $this->mfd('8');
        $this->exame('2'); // MF 4,4: vai ao recurso
        $rui = $this->inscreverAluno($this->c, 'Rui');
        $this->notasCompletas($this->c, $rui, [1 => ['9', '9'], 2 => ['9', '9'], 3 => ['9', '9']]);
        $this->lancarExame($this->c['turma']->id, $this->c['disciplina']->id, $rui->id, '10');
        $servico = app(PautaFinalService::class);
        $turma = $this->c['turma']->id;

        // Antes de a 1.ª fase estar aprovada não há recurso, nem pauta do recurso.
        try {
            $this->lancarRecurso($turma, $this->c['disciplina']->id, $this->inscricao->id, '8');
            $this->fail('Esperava ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('pauta ainda não foi aprovada', $e->errors()['pauta'][0]);
        }
        $this->assertStringContainsString('pauta ainda não foi aprovada', $servico->paraTurma($turma, FasePauta::RECURSO)['bloqueios'][0]);
        $this->expectExceptionObject(ValidationException::withMessages(['pauta' => 'Aprove primeiro a pauta.']));
        app(AprovarPautaAction::class)->executar($turma, FasePauta::RECURSO);
    }

    public function test_depois_da_1_fase_a_pauta_do_recurso_so_lista_quem_foi_e_fecha_o_processo(): void
    {
        $this->mfd('8');
        $this->exame('2');
        $rui = $this->inscreverAluno($this->c, 'Rui');
        $this->notasCompletas($this->c, $rui, [1 => ['9', '9'], 2 => ['9', '9'], 3 => ['9', '9']]);
        $this->lancarExame($this->c['turma']->id, $this->c['disciplina']->id, $rui->id, '10');
        $servico = app(PautaFinalService::class);
        $turma = $this->c['turma']->id;

        app(AprovarPautaAction::class)->executar($turma);
        $fase1 = $servico->paraTurma($turma);
        $this->assertSame(['Recurso', 'Apto'], array_column(array_column($fase1['alunos'], 'decisao'), 'rotulo')); // Ana (recurso), Rui (apto)

        // Só a Ana está na pauta do recurso, e a pauta da 1.ª fase já não se reabre com a do recurso definitiva.
        $this->lancarRecurso($turma, $this->c['disciplina']->id, $this->inscricao->id, '8');
        $recurso = $servico->paraTurma($turma, FasePauta::RECURSO);
        $this->assertCount(1, $recurso['alunos']);
        $this->assertTrue($recurso['pode_aprovar']);
        app(AprovarPautaAction::class)->executar($turma, FasePauta::RECURSO);
        $this->assertTrue($servico->definitiva($turma, FasePauta::RECURSO));

        $this->expectException(ValidationException::class);
        app(\Modules\Avaliacao\Actions\ReabrirPautaAction::class)->executar(new \Modules\Avaliacao\DTO\ReabrirPautaDTO($turma, 'Erro'));
    }
}
